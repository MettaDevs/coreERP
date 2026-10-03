<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\Datasets;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Dataset analitik yang didaftarkan module, padanan daftar query object yang dikenal Business Central.
 *
 * Isinya ditulis penyedia layanan module saat boot (`Datasets::register()`); Core tidak menyimpan
 * daftar tangan dan tidak menulis nama tabel module di mana pun. Diikat sebagai singleton lewat
 * `CoreServices::SINGLETON_BINDINGS` — diikat biasa, setiap pendaftaran masuk ke salinan yang langsung
 * dibuang.
 *
 * Dua tahap, dan pemisahnya disengaja:
 *
 * - **Definisi dibaca sekali per proses**, saat dataset pertama kali diminta, bukan saat boot.
 *   `definition()` tidak menyentuh database, tetapi boot berjalan juga untuk `config:cache` dan
 *   `route:cache`, dan dataset rusak tidak boleh menjatuhkan keduanya. Pemeriksaannya minimal — kode
 *   berawalan id module, model ber-`BelongsToTenant`, nama kolom berbentuk pengenal, measure uang
 *   menyebut kolom mata uangnya — dan dataset yang gagal dilewati dengan `Log::warning`, sama seperti
 *   registry module melewati manifest rusak. Pemeriksaan lengkap (kolom benar-benar ada, permission
 *   ada di manifest, klasifikasi) milik `DatasetValidator` di area 1.
 * - **Field dibaca dari database setiap kali dataset diminta**, lewat `TableFields` yang menyimpan tipe
 *   kolom per nama database. Satu proses dapat melayani beberapa database environment, dan field yang
 *   dibekukan dari database pertama belum tentu benar untuk yang berikutnya. Database yang belum punya
 *   tabel dataset (module belum dipasang di environment itu) menjawab dataset tidak tersedia.
 */
final class DatasetRegistry implements Datasets
{
    /** Nama kolom dan kunci yang boleh masuk SQL lewat `wrap()`: huruf kecil, angka, garis bawah. */
    private const IDENTIFIER = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var list<Dataset> */
    private array $registered = [];

    /**
     * @var array<string, array{module: string, caption: string, model: class-string<Model>, permission: string, policy: array{code: string, legal_entity: string, operating_unit: ?string}|null, only: list<string>, except: list<string>, from_model: bool, measures: array<string, CompiledMeasure>, times: list<string>, default_time: ?string, version: int}>|null
     */
    private ?array $definitions = null;

    public function register(Dataset $dataset): void
    {
        $this->registered[] = $dataset;
        $this->definitions = null;
    }

    /** Dataset menurut kodenya, atau null bila tidak terdaftar, definisinya rusak, atau tabelnya tidak ada di database ini. */
    public function find(string $code): ?CompiledDataset
    {
        $definition = $this->definitions()[$code] ?? null;

        return $definition === null ? null : $this->compile($code, $definition);
    }

    /**
     * Semua dataset yang terdaftar dan sah, urut kode supaya hasilnya sama antar proses.
     *
     * @return list<CompiledDataset>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->definitions() as $code => $definition) {
            $compiled = $this->compile($code, $definition);
            if ($compiled !== null) {
                $out[] = $compiled;
            }
        }

        return $out;
    }

    public static function isIdentifier(string $name): bool
    {
        return preg_match(self::IDENTIFIER, $name) === 1;
    }

    /**
     * @return array<string, array{module: string, caption: string, model: class-string<Model>, permission: string, policy: array{code: string, legal_entity: string, operating_unit: ?string}|null, only: list<string>, except: list<string>, from_model: bool, measures: array<string, CompiledMeasure>, times: list<string>, default_time: ?string, version: int}>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $out = [];
        foreach ($this->registered as $dataset) {
            $raw = $dataset->definition()->toArray();
            $code = is_string($raw['code'] ?? null) ? $raw['code'] : '';

            try {
                $definition = $this->read($dataset->moduleId(), $raw);
            } catch (InvalidDatasetDefinition $e) {
                // Tanpa data tenant: yang dicatat hanya kode dataset, module, dan sebabnya.
                Log::warning('Dataset analitik dilewati: '.$e->getMessage(), ['dataset' => $code, 'module' => $dataset->moduleId()]);

                continue;
            }

            if (isset($out[$code])) {
                Log::warning('Dataset analitik dilewati: kodenya sudah dipakai dataset lain.', ['dataset' => $code, 'module' => $dataset->moduleId()]);

                continue;
            }

            $out[$code] = $definition;
        }

        ksort($out);

        return $this->definitions = $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{module: string, caption: string, model: class-string<Model>, permission: string, policy: array{code: string, legal_entity: string, operating_unit: ?string}|null, only: list<string>, except: list<string>, from_model: bool, measures: array<string, CompiledMeasure>, times: list<string>, default_time: ?string, version: int}
     *
     * @throws InvalidDatasetDefinition
     */
    private function read(string $moduleId, array $raw): array
    {
        $code = $raw['code'] ?? null;
        if (! is_string($code) || ! str_starts_with($code, $moduleId.'.') || $code === $moduleId.'.') {
            throw new InvalidDatasetDefinition('kode dataset harus berawalan id module "'.$moduleId.'.".');
        }

        $model = $raw['model'] ?? null;
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidDatasetDefinition('dataset butuh model sebagai sumbernya.');
        }
        if (! in_array(BelongsToTenant::class, class_uses_recursive($model), true)) {
            throw new InvalidDatasetDefinition('model dataset harus memakai BelongsToTenant, supaya penyaringan tenant milik model.');
        }

        $permission = $raw['permission'] ?? null;
        if (! is_string($permission) || ! str_starts_with($permission, $moduleId.'.')) {
            throw new InvalidDatasetDefinition('dataset butuh permission baca milik module-nya sendiri.');
        }

        $policy = $raw['policy'] ?? null;
        if ($policy !== null) {
            if (! is_array($policy) || ! is_string($policy['code'] ?? null) || ! is_string($policy['legal_entity'] ?? null)) {
                throw new InvalidDatasetDefinition('kebijakan data butuh kode dan kolom legal entity.');
            }
            $operatingUnit = $policy['operating_unit'] ?? null;
            $policy = [
                'code' => $policy['code'],
                'legal_entity' => $this->identifier($policy['legal_entity'], 'kolom legal entity kebijakan'),
                'operating_unit' => $operatingUnit === null ? null : $this->identifier($operatingUnit, 'kolom unit kerja kebijakan'),
            ];
        }

        $fromModel = $raw['fromModel'] ?? null;

        return [
            'module' => $moduleId,
            'caption' => is_string($raw['caption'] ?? null) ? $raw['caption'] : $code,
            'model' => $model,
            'permission' => $permission,
            'policy' => $policy,
            'from_model' => is_array($fromModel),
            'only' => $this->names(is_array($fromModel) ? ($fromModel['only'] ?? []) : []),
            'except' => $this->names(is_array($fromModel) ? ($fromModel['except'] ?? []) : []),
            'measures' => $this->measures($raw['measures'] ?? []),
            'times' => array_map(fn (string $time): string => $this->identifier($time, 'field waktu'), $this->names($raw['times'] ?? [])),
            'default_time' => is_string($raw['defaultTime'] ?? null) ? $raw['defaultTime'] : null,
            'version' => is_int($raw['version'] ?? null) ? $raw['version'] : 1,
        ];
    }

    /**
     * @return array<string, CompiledMeasure>
     *
     * @throws InvalidDatasetDefinition
     */
    private function measures(mixed $measures): array
    {
        if (! is_array($measures) || $measures === []) {
            throw new InvalidDatasetDefinition('dataset butuh sedikitnya satu measure.');
        }

        $out = [];
        foreach ($measures as $key => $measure) {
            $key = $this->identifier((string) $key, 'kunci measure');
            if (! is_array($measure) || ! ($measure['aggregate'] ?? null) instanceof Aggregate || ! ($measure['format'] ?? null) instanceof MeasureFormat) {
                throw new InvalidDatasetDefinition("measure {$key} tidak berbentuk benar.");
            }

            $field = $this->optionalIdentifier($measure['field'] ?? null, "kolom measure {$key}");
            $currency = $this->optionalIdentifier($measure['currency'] ?? null, "kolom mata uang measure {$key}");
            $unit = $this->optionalIdentifier($measure['unit'] ?? null, "kolom satuan measure {$key}");

            // Uang tidak pernah dijumlah lintas mata uang dan kuantitas tidak lintas satuan (KA-22):
            // compiler menambahkan kolom itu sebagai dimensi tersirat, jadi kolomnya wajib disebut.
            if ($measure['format'] === MeasureFormat::Money && $currency === null) {
                throw new InvalidDatasetDefinition("measure uang {$key} wajib menyebut kolom mata uangnya.");
            }
            if ($measure['format'] === MeasureFormat::Quantity && $unit === null) {
                throw new InvalidDatasetDefinition("measure kuantitas {$key} wajib menyebut kolom satuannya.");
            }
            if ($measure['aggregate'] !== Aggregate::Count && $field === null) {
                throw new InvalidDatasetDefinition("measure {$key} butuh kolom yang dihitung.");
            }

            $where = is_array($measure['where'] ?? null) ? $measure['where'] : [];
            foreach (array_keys($where) as $column) {
                $this->identifier((string) $column, "saringan tetap measure {$key}");
            }

            /** @var array<string, list<string|int|bool|null>|string|int|bool|null> $where */
            $out[$key] = new CompiledMeasure(
                key: $key,
                caption: is_string($measure['caption'] ?? null) ? $measure['caption'] : $key,
                aggregate: $measure['aggregate'],
                field: $field,
                format: $measure['format'],
                currency: $currency,
                unit: $unit,
                where: $where,
            );
        }

        return $out;
    }

    /**
     * @param  array{module: string, caption: string, model: class-string<Model>, permission: string, policy: array{code: string, legal_entity: string, operating_unit: ?string}|null, only: list<string>, except: list<string>, from_model: bool, measures: array<string, CompiledMeasure>, times: list<string>, default_time: ?string, version: int}  $definition
     */
    private function compile(string $code, array $definition): ?CompiledDataset
    {
        $model = new $definition['model'];
        $table = $model->getTable();

        // Database environment ini belum punya tabelnya: module-nya belum dipasang di sini. Itu keadaan wajar
        // pada database per environment, bukan cacat, jadi tidak dicatat. Datasetnya memang tidak tersedia di
        // database ini — jawabannya sama dengan dataset yang tidak terdaftar — dan dataset lain tetap berjalan.
        if (! $model->getConnection()->getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        $fields = [];
        if ($definition['from_model']) {
            foreach (TableFields::for($definition['model'], $table) as $field) {
                if (($definition['only'] !== [] && ! in_array($field->key, $definition['only'], true))
                    || in_array($field->key, $definition['except'], true)) {
                    continue;
                }
                $fields[$field->key] = $field;
            }
        }

        return new CompiledDataset(
            code: $code,
            caption: $definition['caption'],
            moduleId: $definition['module'],
            version: $definition['version'],
            model: $definition['model'],
            table: $table,
            permission: $definition['permission'],
            policy: $definition['policy'],
            fields: $fields,
            measures: $definition['measures'],
            times: $definition['times'],
            defaultTime: $definition['default_time'],
        );
    }

    /** @throws InvalidDatasetDefinition */
    private function identifier(mixed $name, string $what): string
    {
        if (! is_string($name) || ! self::isIdentifier($name)) {
            throw new InvalidDatasetDefinition($what.' harus berupa huruf kecil, angka, dan garis bawah.');
        }

        return $name;
    }

    /** @throws InvalidDatasetDefinition */
    private function optionalIdentifier(mixed $name, string $what): ?string
    {
        return $name === null ? null : $this->identifier($name, $what);
    }

    /** @return list<string> */
    private function names(mixed $names): array
    {
        return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }
}
