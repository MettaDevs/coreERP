<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\License\Support\SiteLicense;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\Datasets;
use App\Platform\Modules\Models\ModuleInstallation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dataset analitik yang didaftarkan module, padanan daftar query object yang dikenal Business Central.
 *
 * Isinya ditulis penyedia layanan module saat boot (`Datasets::register()`); Core tidak menyimpan
 * daftar tangan dan tidak menulis nama tabel module di mana pun. Diikat sebagai singleton lewat
 * `CoreServices::SINGLETON_BINDINGS` — diikat biasa, setiap pendaftaran masuk ke salinan yang langsung
 * dibuang.
 *
 * Dua tahap {@see DatasetValidator}, masing-masing dibaca malas dan disimpan selama proses hidup:
 *
 * - **Definisi, sekali per proses**, saat dataset pertama kali diminta, bukan saat boot: boot berjalan
 *   juga untuk `config:cache` dan `route:cache`, dan dataset rusak tidak boleh menjatuhkan keduanya.
 * - **Hasil kompilasi, sekali per database.** Satu proses — terutama pekerja FrankenPHP yang hidup lama —
 *   melayani beberapa database environment, dan kolom satu database belum tentu sama dengan yang lain.
 *   Yang disimpan hanya definisi dan skema, **tidak pernah data tenant**: pemasangan module per tenant
 *   dan lisensi dibaca ulang di setiap {@see self::forTenant()}.
 *
 * Dataset rusak dilewati dengan `Log::warning` yang hanya menyebut kode dataset, module, dan sebabnya,
 * sama seperti registry module melewati manifest rusak. Dataset yang tabelnya belum ada di database ini
 * (module-nya belum dipasang di environment itu) tidak tersedia tanpa peringatan, dan **tidak disimpan**:
 * module yang dipasang sesudahnya di proses yang sama langsung terbaca.
 */
final class DatasetRegistry implements Datasets
{
    /** Nama kolom dan kunci yang boleh masuk SQL lewat `wrap()`: huruf kecil, angka, garis bawah. */
    private const IDENTIFIER = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var list<Dataset> */
    private array $registered = [];

    /** @var array<string, DeclaredDataset>|null definisi sah per kode, urut kode */
    private ?array $declared = null;

    /** @var array<string, array<string, CompiledDataset|InvalidDatasetDefinition>> per database, lalu per kode */
    private array $compiled = [];

    public function __construct(private readonly DatasetValidator $validator) {}

    public function register(Dataset $dataset): void
    {
        $this->registered[] = $dataset;
        $this->declared = null;
        $this->compiled = [];
    }

    /** Dataset menurut kodenya, atau null bila tidak terdaftar, definisinya rusak, atau tabelnya tidak ada di database ini. */
    public function find(string $code): ?CompiledDataset
    {
        $declared = $this->declared()[$code] ?? null;

        return $declared === null ? null : $this->compiled($declared);
    }

    /**
     * Semua dataset yang terdaftar dan sah di database ini, urut kode supaya hasilnya sama antar proses.
     * Termasuk dataset module yang tidak terpasang untuk tenant mana pun; untuk satu tenant pakai
     * {@see self::forTenant()}.
     *
     * @return list<CompiledDataset>
     */
    public function all(): array
    {
        return $this->compiledAll(array_values($this->declared()));
    }

    /**
     * Dataset yang boleh ditawarkan kepada tenant ini: module-nya terpasang untuk tenant itu (catatan
     * `core_module_installations`, bukan entitlement) dan berlisensi di server ini. Permission baca
     * resource tetap diperiksa per principal oleh `DatasetAccess`.
     *
     * @return list<CompiledDataset>
     */
    public function forTenant(string $tenantId): array
    {
        $installed = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ModuleInstallation::STATUS_INSTALLED)
            ->pluck('module_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        // Dibaca dari wadah setiap kali, bukan disuntikkan: lisensi diikat `scoped`, dan registry ini hidup
        // sepanjang proses.
        $license = app(SiteLicense::class);

        return $this->compiledAll(array_values(array_filter(
            $this->declared(),
            static fn (DeclaredDataset $declared): bool => in_array($declared->moduleId, $installed, true) && $license->allowsApp($declared->moduleId),
        )));
    }

    /**
     * Hasil pemeriksaan setiap dataset terdaftar terhadap database ini, dihitung ulang tanpa simpanan
     * dan tanpa log. Untuk `analytics:datasets` dan `AnalyticsDatasetsBoundaryTest`, yang harus melihat
     * dataset rusak, bukan melewatinya.
     *
     * @return list<array{module: string, code: string, status: 'valid'|'unavailable'|'invalid', dataset: ?CompiledDataset, problem: ?string}>
     */
    public function diagnose(): array
    {
        $out = [];
        $seen = [];

        foreach ($this->registered as $dataset) {
            $row = ['module' => $dataset->moduleId(), 'code' => $dataset->definition()->code, 'status' => 'invalid', 'dataset' => null, 'problem' => null];

            try {
                $declared = $this->validator->declare($dataset);
                if (isset($seen[$declared->code])) {
                    throw new InvalidDatasetDefinition('kodenya sudah dipakai dataset lain.');
                }
                $seen[$declared->code] = true;

                if ($this->validator->available($declared)) {
                    $row['dataset'] = $this->validator->compile($declared);
                    $row['status'] = 'valid';
                } else {
                    $row['status'] = 'unavailable';
                    $row['problem'] = 'tabel dasarnya belum ada di database ini (module belum dipasang di sini).';
                }
            } catch (InvalidDatasetDefinition $e) {
                $row['problem'] = $e->getMessage();
            }

            $out[] = $row;
        }

        usort($out, static fn (array $a, array $b): int => [$a['module'], $a['code']] <=> [$b['module'], $b['code']]);

        return $out;
    }

    /** @return list<Dataset> */
    public function registered(): array
    {
        return $this->registered;
    }

    public static function isIdentifier(string $name): bool
    {
        return preg_match(self::IDENTIFIER, $name) === 1;
    }

    /** @return array<string, DeclaredDataset> */
    private function declared(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $out = [];
        foreach ($this->registered as $dataset) {
            $module = $dataset->moduleId();

            try {
                $declared = $this->validator->declare($dataset);
            } catch (Throwable $e) {
                // Kode module yang melempar apa pun saat menyusun definisinya adalah dataset rusak, bukan
                // aplikasi yang rusak. Tanpa data tenant: yang dicatat hanya module dan sebabnya.
                Log::warning('Dataset analitik dilewati: '.$e->getMessage(), ['module' => $module, 'exception' => $e::class]);

                continue;
            }

            if (isset($out[$declared->code])) {
                Log::warning('Dataset analitik dilewati: kodenya sudah dipakai dataset lain.', ['dataset' => $declared->code, 'module' => $module]);

                continue;
            }

            $out[$declared->code] = $declared;
        }

        ksort($out);

        return $this->declared = $out;
    }

    /**
     * @param  list<DeclaredDataset>  $declared
     * @return list<CompiledDataset>
     */
    private function compiledAll(array $declared): array
    {
        $out = [];
        foreach ($declared as $dataset) {
            $compiled = $this->compiled($dataset);
            if ($compiled !== null) {
                $out[] = $compiled;
            }
        }

        return $out;
    }

    private function compiled(DeclaredDataset $declared): ?CompiledDataset
    {
        $database = $this->database($declared);
        $known = $this->compiled[$database][$declared->code] ?? null;

        if ($known === null) {
            // Tidak disimpan: tabel yang belum ada sekarang dapat dibuat pemasangan module berikutnya.
            if (! $this->validator->available($declared)) {
                return null;
            }

            try {
                $known = $this->validator->compile($declared);
            } catch (InvalidDatasetDefinition $e) {
                Log::warning('Dataset analitik dilewati: '.$e->getMessage(), ['dataset' => $declared->code, 'module' => $declared->moduleId]);
                $known = $e;
            }
            $this->compiled[$database][$declared->code] = $known;
        }

        return $known instanceof CompiledDataset ? $known : null;
    }

    /**
     * Kunci database koneksi model dataset saat ini. Koneksi bawaan bernama sama untuk setiap environment
     * tetapi menunjuk database yang berbeda dari permintaan ke permintaan, jadi yang dipakai alamat dan
     * nama databasenya, bukan nama koneksinya.
     */
    private function database(DeclaredDataset $declared): string
    {
        $connection = (new $declared->model)->getConnection();

        return json_encode([
            $connection->getConfig('host'),
            $connection->getConfig('port'),
            $connection->getDatabaseName(),
            $connection->getConfig('search_path'),
        ], JSON_THROW_ON_ERROR);
    }
}
