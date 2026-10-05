<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\AuditColumns;
use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use App\Platform\Modules\Contracts\TableFields;
use App\Platform\Modules\Support\ModuleManifest;
use App\Platform\Modules\Support\ModuleManifestFiles;
use App\Platform\Modules\Support\ModuleRegistry;
use App\Platform\Modules\Support\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Aturan definisi dataset analitik, satu tempat untuk semua module. Tabel aturannya — beserta alasan
 * setiap aturan — ada di `docs/todo/analitik/model-semantik.md` bagian *Yang diperiksa DatasetValidator*.
 *
 * Dua tahap, karena jawabannya berlaku untuk lingkup yang berbeda:
 *
 * - {@see self::declare()} tanpa database: kode, sumber, namespace module, permission dan kebijakan di
 *   manifest module, bentuk setiap pernyataan. Hasilnya sama untuk setiap tenant dan setiap database,
 *   jadi registry menyimpannya sekali per proses.
 * - {@see self::compile()} terhadap database koneksi model saat ini: kolom ada di tabelnya, tipe kolom
 *   untuk measure dan waktu, klasifikasi setiap field. Satu proses dapat melayani beberapa database
 *   environment, jadi registry menyimpannya per database.
 *
 * Pelanggaran dilempar sebagai {@see InvalidDatasetDefinition} dengan pesan untuk pengembang module.
 * Pesannya tidak pernah memuat data tenant: hanya kode, nama kolom, dan nama tabel.
 */
final class DatasetValidator
{
    /** Kunci field, measure, dan alias join: `snake_case`, paling panjang 64 karakter. */
    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Bagian kode dataset sesudah id module, misalnya `asset-register`. */
    private const CODE_NAME = '/^[a-z][a-z0-9-]{0,63}$/';

    /** Alias berbentuk huruf lalu angka (`d0`, `c0`, `m0`, `r0`) dipakai engine di SQL. */
    private const ENGINE_ALIAS = '/^[a-z][0-9]+$/';

    /** Tipe kolom angka PostgreSQL; dipakai juga `QueryValidator` untuk measure terkecil dan terbesar (area 13). */
    public const NUMERIC = ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'];

    private const TIME = ['date', 'timestamp', 'timestamptz'];

    /** @var array<string, array{permissions: array<string, string>, policies: array<string, list<string>>}> per id module */
    private array $manifests = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public static function isKey(string $name): bool
    {
        return preg_match(self::KEY, $name) === 1;
    }

    /**
     * Tahap tanpa database. Memanggil `definition()` milik module dan, untuk dataset bersumber query,
     * closure sumbernya — keduanya hanya menyusun pernyataan, tidak membaca data.
     *
     * @throws InvalidDatasetDefinition
     */
    public function declare(Dataset $dataset): DeclaredDataset
    {
        $moduleId = $dataset->moduleId();
        $raw = $dataset->definition()->toArray();

        $code = $raw['code'] ?? null;
        if (! is_string($code) || ! str_starts_with($code, $moduleId.'.') || preg_match(self::CODE_NAME, substr($code, strlen($moduleId) + 1)) !== 1) {
            throw new InvalidDatasetDefinition('kode dataset harus berawalan id module "'.$moduleId.'." lalu nama berhuruf kecil, angka, dan tanda hubung.');
        }
        $caption = $this->text($raw['caption'] ?? null, 'dataset butuh nama tampilan.');

        $model = $raw['model'] ?? null;
        $source = $raw['source'] ?? null;
        if (($model === null) === ($source === null)) {
            throw new InvalidDatasetDefinition('dataset butuh tepat satu sumber: model() atau fromQuery().');
        }
        $builder = null;
        if ($source !== null) {
            $builder = $source instanceof Closure ? $source() : null;
            if (! $builder instanceof Builder) {
                throw new InvalidDatasetDefinition('query sumber harus memulangkan query Eloquent dari model module.');
            }
            $model = $builder->getModel()::class;
        }

        $model = $this->tenantModel($model, 'model dataset');
        $module = $this->module($moduleId);
        $this->ownModel($model, $module, 'model dataset');
        if ($builder !== null && ! array_key_exists(TenantScope::class, $this->scopesOf($builder))) {
            throw new InvalidDatasetDefinition('query sumber tidak boleh melepas penyaringan tenant model; susun dari Model::query().');
        }

        $manifest = $this->manifest($module);
        $permission = $raw['permission'] ?? null;
        if (! is_string($permission) || ($manifest['permissions'][$permission] ?? null) !== 'read') {
            throw new InvalidDatasetDefinition('permission '.(is_string($permission) ? $permission : '(kosong)').' tidak ada di manifest module '.$moduleId.' dengan access read.');
        }

        $joins = $this->joins($raw['joins'] ?? [], $module, $source !== null);
        $policy = $this->policy($raw['policy'] ?? null, $manifest, $joins);
        $protectors = array_keys(array_filter($manifest['policies'], static fn (array $protected): bool => in_array($permission, $protected, true)));
        if ($protectors !== [] && ($policy === null || ! in_array($policy['code'], $protectors, true))) {
            throw new InvalidDatasetDefinition('resource ini dibatasi kebijakan '.implode(', ', $protectors).'; nyatakan kolom kebijakannya lewat dataPolicy().');
        }

        $fromModel = $raw['fromModel'] ?? null;
        if ($fromModel !== null && $source !== null) {
            throw new InvalidDatasetDefinition('dataset bersumber query tidak punya katalog model; nyatakan field satu per satu dengan field().');
        }

        $fields = $this->fields($raw['fields'] ?? [], $joins, $source !== null);
        $measures = $this->measures($raw['measures'] ?? [], $joins);
        $times = $this->names($raw['times'] ?? [], 'field waktu');
        $defaultTime = $raw['defaultTime'] ?? null;
        if ($defaultTime !== null && (! is_string($defaultTime) || ! in_array($defaultTime, $times, true))) {
            throw new InvalidDatasetDefinition('field waktu utama harus salah satu field waktu.');
        }

        $recordRoute = $raw['recordRoute'] ?? null;
        if ($recordRoute !== null && (! is_string($recordRoute) || ! str_starts_with($recordRoute, '/'.$moduleId.'/') || ! str_contains($recordRoute, '{id}'))) {
            throw new InvalidDatasetDefinition('rute record harus berawalan /'.$moduleId.'/ dan memuat {id}.');
        }

        $version = $raw['version'] ?? 1;
        if (! is_int($version) || $version < 1) {
            throw new InvalidDatasetDefinition('versi dataset harus bilangan bulat 1 atau lebih.');
        }

        return new DeclaredDataset(
            code: $code,
            caption: $caption,
            moduleId: $moduleId,
            description: $this->optionalText($raw['description'] ?? null, 'penjelasan dataset harus berupa teks.'),
            model: $model,
            source: $source instanceof Closure ? $source : null,
            permission: $permission,
            policy: $policy,
            fromModel: $fromModel === null ? null : [
                'only' => $this->names(is_array($fromModel) ? ($fromModel['only'] ?? []) : null, 'kolom fieldsFromModel'),
                'except' => $this->names(is_array($fromModel) ? ($fromModel['except'] ?? []) : null, 'kolom fieldsFromModel'),
            ],
            fields: $fields,
            joins: $joins,
            references: $this->references($raw['references'] ?? [], $module),
            shared: $this->shared($raw['shared'] ?? []),
            measures: $measures,
            times: $times,
            defaultTime: $defaultTime,
            recordRoute: $recordRoute,
            version: $version,
            renamed: $this->renamed($raw['renamed'] ?? []),
        );
    }

    /**
     * Tabel dasar dataset ada di database koneksi modelnya. Tidak ada berarti module-nya belum dipasang
     * di database environment ini: keadaan wajar pada database per environment, bukan cacat.
     */
    public function available(DeclaredDataset $declared): bool
    {
        $model = new $declared->model;

        return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
    }

    /**
     * Tahap terhadap database koneksi model saat ini. Pemanggil memastikan {@see self::available()} lebih
     * dulu; tabel dasar yang tidak ada di sini dilaporkan sebagai pelanggaran.
     *
     * @throws InvalidDatasetDefinition
     */
    public function compile(DeclaredDataset $declared): CompiledDataset
    {
        $instance = new $declared->model;
        $sourceSql = null;
        if ($declared->source === null) {
            $table = $instance->getTable();
            $columns = $this->columnsOf($instance);
            if ($columns === []) {
                throw new InvalidDatasetDefinition("tabel {$table} belum ada di database ini.");
            }
        } else {
            $table = CompiledDataset::SOURCE_ALIAS;
            $builder = ($declared->source)();
            $sourceSql = [$builder->getQuery()->toSql(), $builder->getQuery()->getBindings()];
            $columns = $this->sourceColumns($builder);
            if (! isset($columns['tenant_id'])) {
                throw new InvalidDatasetDefinition('query sumber wajib memilih kolom tenant_id; Core menyaring tenant sekali lagi di query luar.');
            }
        }

        /** @var array<string, array{table: string, label: string, columns: array<string, string>, model: class-string<Model>}> $tables alias '' = tabel dasar */
        $tables = ['' => ['table' => $table, 'label' => $declared->source === null ? 'tabel '.$table : 'query sumber', 'columns' => $columns, 'model' => $declared->model]];
        $joins = [];
        foreach ($declared->joins as $alias => $join) {
            $joinModel = new $join['model'];
            $joinColumns = $this->columnsOf($joinModel);
            if ($joinColumns === []) {
                throw new InvalidDatasetDefinition("tabel {$joinModel->getTable()} untuk join {$alias} belum ada di database ini.");
            }
            [$local] = $this->resolve($join['local'], $tables, "kolom lokal join {$alias}");
            $tables[$alias] = ['table' => $alias, 'label' => 'tabel '.$joinModel->getTable(), 'columns' => $joinColumns, 'model' => $join['model']];
            [$foreign] = $this->resolve($alias.'.'.$join['foreign'], $tables, "kolom tujuan join {$alias}");
            $joins[$alias] = new CompiledJoin(
                alias: $alias,
                model: $join['model'],
                table: $joinModel->getTable(),
                localColumn: $local,
                foreignColumn: $foreign,
                includeArchived: $join['include_archived'],
                archivable: in_array(SoftDeletes::class, class_uses_recursive($join['model']), true),
            );
        }

        /** @var array<string, FilterField> $fields */
        $fields = [];
        /** @var array<string, ?DataClass> $classes */
        $classes = [];
        /** @var array<string, string> $types */
        $types = [];

        if ($declared->fromModel !== null) {
            $catalog = [];
            foreach (TableFields::for($declared->model, $table) as $field) {
                $catalog[$field->key] = $field;
            }
            foreach ([...$declared->fromModel['only'], ...$declared->fromModel['except']] as $name) {
                if (! isset($columns[$name])) {
                    throw new InvalidDatasetDefinition("kolom {$name} di fieldsFromModel() tidak ada di tabel {$table}.");
                }
            }
            foreach ($declared->fromModel['only'] as $name) {
                if (! isset($catalog[$name])) {
                    throw new InvalidDatasetDefinition("kolom {$name} tidak ada di katalog field model (tersembunyi atau belum bernama); nyatakan dengan field().");
                }
            }
            foreach ($catalog as $key => $field) {
                if (($declared->fromModel['only'] !== [] && ! in_array($key, $declared->fromModel['only'], true))
                    || in_array($key, $declared->fromModel['except'], true)) {
                    continue;
                }
                $fields[$key] = $field;
                $classes[$key] = $this->classification($declared->model, $key);
                $types[$key] = $columns[$key];
            }
        }

        foreach ($declared->fields as $key => $field) {
            [$column, $type, $owner, $name] = $this->resolve($field['column'] ?? $key, $tables, "field {$key}");
            $fields[$key] = new FilterField($key, $field['caption'], $field['type'], $column, $field['options'], $field['type'] === FieldType::Reference ? $this->lookup($owner, $name) : null);
            $classes[$key] = $field['classification'] ?? $this->classification($owner, $name);
            $types[$key] = $type;
        }

        $captions = $this->constant($declared->model, 'FIELD_CAPTIONS');
        $references = [];
        foreach ($declared->references as $key => $reference) {
            if (! isset($fields[$key])) {
                $caption = $captions[$key] ?? null;
                if ($declared->source !== null || ! is_string($caption) || $caption === '') {
                    throw new InvalidDatasetDefinition("rujukan {$key} belum punya field bernama; nyatakan dengan field() atau beri nama di FIELD_CAPTIONS model.");
                }
                [$fields[$key], $classes[$key], $types[$key]] = $this->baseField($key, $caption, $tables);
            } elseif (! in_array($fields[$key]->type, [FieldType::Reference, FieldType::Text], true)) {
                throw new InvalidDatasetDefinition("rujukan {$key} harus menunjuk kolom id, bukan field bertipe {$fields[$key]->type->value}.");
            } elseif ($fields[$key]->type === FieldType::Text) {
                $field = $fields[$key];
                $fields[$key] = new FilterField($field->key, $field->caption, FieldType::Reference, $field->column, [], $field->lookup);
            }

            $master = new $reference['model'];
            $masterColumns = $this->columnsOf($master);
            foreach (array_filter(['id', $reference['label'], $reference['code']]) as $column) {
                if (! isset($masterColumns[$column])) {
                    throw new InvalidDatasetDefinition("kolom {$column} tidak ada di tabel {$master->getTable()} (rujukan {$key}).");
                }
            }
            $alias = 'r'.count($references);
            $references[$key] = new CompiledReference(
                key: $key,
                model: $reference['model'],
                table: $master->getTable(),
                alias: $alias,
                localColumn: $fields[$key]->column,
                foreignColumn: $alias.'.id',
                labelColumn: $alias.'.'.$reference['label'],
                codeColumn: $reference['code'] === null ? null : $alias.'.'.$reference['code'],
            );
        }

        foreach ($declared->shared as $key => $dimension) {
            if (! isset($fields[$key])) {
                if ($declared->source !== null) {
                    throw new InvalidDatasetDefinition("dimensi bersama {$key} butuh field yang dinyatakan dengan field().");
                }
                $caption = $captions[$key] ?? null;
                [$fields[$key], $classes[$key], $types[$key]] = $this->baseField($key, is_string($caption) && $caption !== '' ? $caption : $dimension->caption(), $tables);
            }
        }

        $classifications = [];
        foreach ($fields as $key => $field) {
            if (! self::isKey($key)) {
                throw new InvalidDatasetDefinition("kunci field {$key} harus snake_case, paling panjang 64 karakter.");
            }
            // Kunci yang sama dengan nama kolom tabel dasar wajib menunjuk kolom itu. Kolom kebijakan, mata
            // uang, dan measure ditulis dengan nama kolom, dan `qualified()` mendahulukan kunci field: field
            // yang membayangi kolom lain membuat kebijakan diam-diam menyaring kolom yang salah.
            if (isset($columns[$key]) && $field->column !== $table.'.'.$key) {
                throw new InvalidDatasetDefinition("field {$key} menunjuk {$field->column}, padahal tabel dasar punya kolom {$key}; pakai kunci lain.");
            }
            $class = $classes[$key] ?? null;
            if ($class === null || $class === DataClass::ToBeClassified) {
                throw new InvalidDatasetDefinition("kolom {$key} belum diklasifikasi.");
            }
            if ($class === DataClass::AccountData) {
                throw new InvalidDatasetDefinition("kolom {$key} berkelas AccountData dan tidak boleh masuk dataset.");
            }
            $classifications[$key] = $class;
        }

        foreach ($declared->measures as $key => $measure) {
            if ($measure->field !== null) {
                $type = isset($fields[$measure->field]) ? $types[$measure->field] : $this->resolve($measure->field, $tables, "measure {$key}")[1];
                if (in_array($measure->aggregate, [Aggregate::Sum, Aggregate::Average], true) && ! in_array($type, self::NUMERIC, true)) {
                    throw new InvalidDatasetDefinition("kolom {$measure->field} bukan angka (measure {$key}).");
                }
            }
            foreach ([$measure->currency, $measure->unit] as $column) {
                if ($column !== null && ! isset($fields[$column])) {
                    $this->resolve($column, $tables, "measure {$key}");
                }
            }
            foreach ($measure->where as $fieldKey => $values) {
                $this->measureFilter($key, $fieldKey, $values, $fields[$fieldKey] ?? null);
            }
        }

        if ($declared->policy !== null) {
            $this->resolve($declared->policy['legal_entity'], $tables, 'kolom legal entity kebijakan');
            if ($declared->policy['operating_unit'] !== null) {
                $this->resolve($declared->policy['operating_unit'], $tables, 'kolom unit kerja kebijakan');
            }
        }

        foreach ($declared->times as $time) {
            if (! isset($fields[$time])) {
                throw new InvalidDatasetDefinition("field waktu {$time} bukan field dataset.");
            }
            if (! in_array($types[$time], self::TIME, true)) {
                throw new InvalidDatasetDefinition("field waktu {$time} harus berkolom date, timestamp, atau timestamptz.");
            }
        }

        foreach ($declared->renamed as $old => $new) {
            if (isset($fields[$old]) || isset($declared->measures[$old])) {
                throw new InvalidDatasetDefinition("peta nama {$old} masih dipakai sebagai kunci; kunci lama harus sudah tidak ada.");
            }
            if (! isset($fields[$new]) && ! isset($declared->measures[$new])) {
                throw new InvalidDatasetDefinition("peta nama {$old} menunjuk {$new}, yang tidak ada di dataset.");
            }
        }

        $hash = hash('sha256', json_encode([
            $declared->code, $declared->version, $declared->model, $table, $sourceSql, $declared->permission, $declared->policy,
            $fields, $classifications, $types, $references, $declared->shared, $joins, $declared->measures,
            $declared->times, $declared->defaultTime, $declared->renamed,
        ], JSON_THROW_ON_ERROR));

        return new CompiledDataset(
            code: $declared->code,
            caption: $declared->caption,
            moduleId: $declared->moduleId,
            version: $declared->version,
            model: $declared->model,
            table: $table,
            permission: $declared->permission,
            policy: $declared->policy,
            fields: $fields,
            measures: $declared->measures,
            times: $declared->times,
            defaultTime: $declared->defaultTime,
            description: $declared->description,
            recordRoute: $declared->recordRoute,
            renamed: $declared->renamed,
            classifications: $classifications,
            columnTypes: $types,
            sharedDimensions: $declared->shared,
            references: $references,
            joins: $joins,
            source: $declared->source,
            hash: $hash,
        );
    }

    /** @return class-string<Model> */
    private function tenantModel(mixed $model, string $what): string
    {
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidDatasetDefinition("{$what} harus kelas model Eloquent.");
        }
        if (! in_array(BelongsToTenant::class, class_uses_recursive($model), true)) {
            throw new InvalidDatasetDefinition("{$what} harus memakai BelongsToTenant, supaya penyaringan tenant milik model.");
        }

        return $model;
    }

    /** Module tidak menyentuh tabel module lain: semua model dataset berasal dari namespace module itu. */
    private function ownModel(string $model, ModuleManifest $module, string $what): void
    {
        $namespace = substr($module->serviceProvider(), 0, -strlen('ModuleServiceProvider'));
        if (! str_starts_with($model, $namespace)) {
            throw new InvalidDatasetDefinition("{$what} {$model} bukan milik module {$module->id}; join hanya ke tabel module sendiri.");
        }
    }

    private function module(string $id): ModuleManifest
    {
        // Termasuk module yang sedang dipindah masuk: kodenya dimuat dan boleh mendaftarkan dataset,
        // walau belum dapat dipasang. Ketersediaannya dijaga catatan pemasangan, bukan di sini.
        foreach ($this->modules->allIncludingMoved() as $module) {
            if ($module->id === $id) {
                return $module;
            }
        }

        throw new InvalidDatasetDefinition("module {$id} tidak dikenal runtime.");
    }

    /**
     * Permission dan kebijakan data dari manifest gabungan module (`app.yaml` + `manifest/`), bukan dari
     * database: aturannya harus sama di CI, di test, dan di server yang katalognya belum didaftarkan.
     *
     * @return array{permissions: array<string, string>, policies: array<string, list<string>>}
     */
    private function manifest(ModuleManifest $module): array
    {
        if (isset($this->manifests[$module->id])) {
            return $this->manifests[$module->id];
        }

        try {
            $content = ModuleManifestFiles::read($module->folder);
        } catch (RuntimeException $e) {
            throw new InvalidDatasetDefinition('manifest module '.$module->id.' tidak terbaca: '.$e->getMessage(), 0, $e);
        }

        $security = is_array($content['security'] ?? null) ? $content['security'] : [];
        $permissions = [];
        foreach (is_array($security['permissions'] ?? null) ? $security['permissions'] : [] as $permission) {
            if (is_array($permission) && is_string($permission['code'] ?? null)) {
                $permissions[$permission['code']] = is_string($permission['access'] ?? null) ? $permission['access'] : '';
            }
        }
        $policies = [];
        foreach (is_array($security['data_policies'] ?? null) ? $security['data_policies'] : [] as $policy) {
            if (is_array($policy) && is_string($policy['code'] ?? null)) {
                $policies[$policy['code']] = array_values(array_filter(is_array($policy['protected_permissions'] ?? null) ? $policy['protected_permissions'] : [], 'is_string'));
            }
        }

        return $this->manifests[$module->id] = ['permissions' => $permissions, 'policies' => $policies];
    }

    /**
     * @param  array<string, array{model: class-string<Model>, local: string, foreign: string, include_archived: bool}>  $joins
     * @param  array{permissions: array<string, string>, policies: array<string, list<string>>}  $manifest
     * @return array{code: string, legal_entity: string, operating_unit: ?string}|null
     */
    private function policy(mixed $policy, array $manifest, array $joins): ?array
    {
        if ($policy === null) {
            return null;
        }
        if (! is_array($policy) || ! is_string($policy['code'] ?? null) || ! is_string($policy['legal_entity'] ?? null)) {
            throw new InvalidDatasetDefinition('kebijakan data butuh kode dan kolom legal entity.');
        }
        if (! isset($manifest['policies'][$policy['code']])) {
            throw new InvalidDatasetDefinition('kebijakan data '.$policy['code'].' tidak ada di manifest module.');
        }

        $operatingUnit = $policy['operating_unit'] ?? null;

        return [
            'code' => $policy['code'],
            'legal_entity' => $this->columnRef($policy['legal_entity'], 'kolom legal entity kebijakan', $joins),
            'operating_unit' => $operatingUnit === null ? null : $this->columnRef($operatingUnit, 'kolom unit kerja kebijakan', $joins),
        ];
    }

    /** @return array<string, array{model: class-string<Model>, local: string, foreign: string, include_archived: bool}> */
    private function joins(mixed $joins, ModuleManifest $module, bool $fromQuery): array
    {
        if (! is_array($joins)) {
            throw new InvalidDatasetDefinition('join dataset tidak berbentuk benar.');
        }
        if ($fromQuery && $joins !== []) {
            throw new InvalidDatasetDefinition('dataset bersumber query tidak memakai join; susun join-nya di dalam query sumber.');
        }

        $out = [];
        foreach ($joins as $alias => $join) {
            $alias = (string) $alias;
            if (! self::isKey($alias) || preg_match(self::ENGINE_ALIAS, $alias) === 1) {
                throw new InvalidDatasetDefinition("alias join {$alias} harus snake_case dan bukan huruf diikuti angka (dipakai engine).");
            }
            if (! is_array($join)) {
                throw new InvalidDatasetDefinition("join {$alias} tidak berbentuk benar.");
            }
            $model = $this->tenantModel($join['model'] ?? null, "model join {$alias}");
            $this->ownModel($model, $module, "model join {$alias}");
            $out[$alias] = [
                'model' => $model,
                'local' => $this->columnRef($join['localColumn'] ?? null, "kolom lokal join {$alias}", $out),
                'foreign' => $this->key($join['foreignColumn'] ?? null, "kolom tujuan join {$alias}"),
                'include_archived' => ($join['includeArchived'] ?? false) === true,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{model: class-string<Model>, local: string, foreign: string, include_archived: bool}>  $joins
     * @return array<string, array{caption: string, type: FieldType, column: ?string, options: array<string, string>, classification: ?DataClass}>
     */
    private function fields(mixed $fields, array $joins, bool $fromQuery): array
    {
        if (! is_array($fields)) {
            throw new InvalidDatasetDefinition('field dataset tidak berbentuk benar.');
        }

        $out = [];
        foreach ($fields as $key => $field) {
            $key = $this->key((string) $key, 'kunci field');
            if (! is_array($field) || ! ($field['type'] ?? null) instanceof FieldType) {
                throw new InvalidDatasetDefinition("field {$key} tidak berbentuk benar.");
            }
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            $labels = [];
            foreach ($options as $value => $label) {
                if (! is_string($label)) {
                    throw new InvalidDatasetDefinition("pilihan field {$key} harus berlabel teks.");
                }
                $labels[(string) $value] = $label;
            }
            if ($field['type'] === FieldType::Option && $labels === []) {
                throw new InvalidDatasetDefinition("field pilihan {$key} butuh daftar pilihannya.");
            }
            $classification = $field['classification'] ?? null;
            if ($classification !== null && ! $classification instanceof DataClass) {
                throw new InvalidDatasetDefinition("klasifikasi field {$key} tidak berbentuk benar.");
            }
            if ($fromQuery && $classification === null) {
                throw new InvalidDatasetDefinition("kolom {$key} belum diklasifikasi; field dataset bersumber query wajib menyebut klasifikasinya.");
            }
            $column = $field['column'] ?? null;
            $out[$key] = [
                'caption' => $this->text($field['caption'] ?? null, "field {$key} butuh nama tampilan."),
                'type' => $field['type'],
                'column' => $column === null ? null : $this->columnRef($column, "kolom field {$key}", $joins),
                'options' => $labels,
                'classification' => $classification,
            ];
        }

        return $out;
    }

    /** @return array<string, array{model: class-string<Model>, label: string, code: ?string}> */
    private function references(mixed $references, ModuleManifest $module): array
    {
        if (! is_array($references)) {
            throw new InvalidDatasetDefinition('rujukan dataset tidak berbentuk benar.');
        }

        $out = [];
        foreach ($references as $key => $reference) {
            $key = $this->key((string) $key, 'kunci rujukan');
            if (! is_array($reference)) {
                throw new InvalidDatasetDefinition("rujukan {$key} tidak berbentuk benar.");
            }
            $model = $this->tenantModel($reference['model'] ?? null, "model rujukan {$key}");
            $this->ownModel($model, $module, "model rujukan {$key}");
            $code = $reference['code'] ?? null;
            $out[$key] = [
                'model' => $model,
                'label' => $this->key($reference['label'] ?? null, "kolom label rujukan {$key}"),
                'code' => $code === null ? null : $this->key($code, "kolom kode rujukan {$key}"),
            ];
        }

        return $out;
    }

    /** @return array<string, SharedDimension> */
    private function shared(mixed $shared): array
    {
        if (! is_array($shared)) {
            throw new InvalidDatasetDefinition('dimensi bersama dataset tidak berbentuk benar.');
        }

        $out = [];
        foreach ($shared as $key => $dimension) {
            $key = $this->key((string) $key, 'kunci dimensi bersama');
            if (! $dimension instanceof SharedDimension) {
                throw new InvalidDatasetDefinition("dimensi bersama {$key} tidak berbentuk benar.");
            }
            $out[$key] = $dimension;
        }

        return $out;
    }

    /**
     * @param  array<string, array{model: class-string<Model>, local: string, foreign: string, include_archived: bool}>  $joins
     * @return array<string, CompiledMeasure>
     */
    private function measures(mixed $measures, array $joins): array
    {
        if (! is_array($measures) || $measures === []) {
            throw new InvalidDatasetDefinition('dataset butuh sedikitnya satu measure.');
        }

        $out = [];
        foreach ($measures as $key => $measure) {
            $key = $this->key((string) $key, 'kunci measure');
            if (! is_array($measure) || ! ($measure['aggregate'] ?? null) instanceof Aggregate || ! ($measure['format'] ?? null) instanceof MeasureFormat) {
                throw new InvalidDatasetDefinition("measure {$key} tidak berbentuk benar.");
            }

            $field = $this->optionalColumnRef($measure['field'] ?? null, "kolom measure {$key}", $joins);
            $currency = $this->optionalColumnRef($measure['currency'] ?? null, "kolom mata uang measure {$key}", $joins);
            $unit = $this->optionalColumnRef($measure['unit'] ?? null, "kolom satuan measure {$key}", $joins);

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
            $filters = [];
            foreach ($where as $fieldKey => $values) {
                $fieldKey = $this->key((string) $fieldKey, "saringan tetap measure {$key}");
                $list = is_array($values) ? $values : [$values];
                if ($list === [] || ! array_is_list($list)) {
                    throw new InvalidDatasetDefinition("saringan tetap measure {$key} pada {$fieldKey} butuh satu nilai atau daftar nilai.");
                }
                foreach ($list as $value) {
                    if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                        throw new InvalidDatasetDefinition("saringan tetap measure {$key} pada {$fieldKey} hanya menerima teks, angka bulat, ya/tidak, atau kosong.");
                    }
                }
                /** @var list<string|int|bool|null>|string|int|bool|null $values */
                $filters[$fieldKey] = $values;
            }

            $out[$key] = new CompiledMeasure(
                key: $key,
                caption: $this->text($measure['caption'] ?? null, "measure {$key} butuh nama tampilan."),
                aggregate: $measure['aggregate'],
                field: $field,
                format: $measure['format'],
                currency: $currency,
                unit: $unit,
                where: $filters,
            );
        }

        return $out;
    }

    /** Saringan tetap measure hanya pada field pilihan, ya/tidak, atau rujukan, dengan nilai yang cocok tipenya. */
    private function measureFilter(string $measure, string $key, mixed $values, ?FilterField $field): void
    {
        if ($field === null) {
            throw new InvalidDatasetDefinition("saringan tetap measure {$measure} menyebut {$key}, yang bukan field dataset.");
        }
        if (! in_array($field->type, [FieldType::Option, FieldType::Boolean, FieldType::Reference], true)) {
            throw new InvalidDatasetDefinition("saringan tetap measure {$measure} hanya boleh pada field pilihan, ya/tidak, atau rujukan; {$key} bertipe {$field->type->value}.");
        }

        foreach (is_array($values) ? $values : [$values] as $value) {
            $valid = match (true) {
                $value === null => true,
                $field->type === FieldType::Option => is_string($value) && array_key_exists($value, $field->options),
                $field->type === FieldType::Boolean => is_bool($value),
                default => is_string($value) || is_int($value),
            };
            if (! $valid) {
                throw new InvalidDatasetDefinition("saringan tetap measure {$measure} memuat nilai yang tidak sah untuk field {$key}.");
            }
        }
    }

    /** @return array<string, string> */
    private function renamed(mixed $renamed): array
    {
        if (! is_array($renamed)) {
            throw new InvalidDatasetDefinition('peta nama versi tidak berbentuk benar.');
        }

        $out = [];
        foreach ($renamed as $old => $new) {
            $out[$this->key((string) $old, 'kunci lama peta nama')] = $this->key($new, 'kunci baru peta nama');
        }

        return $out;
    }

    /**
     * Kolom dari tabel dasar (`kolom`) atau dari join yang dinyatakan (`alias.kolom`), berkualifikasi,
     * beserta tipe database, model pemilik, dan nama kolomnya.
     *
     * @param  array<string, array{table: string, label: string, columns: array<string, string>, model: class-string<Model>}>  $tables
     * @return array{0: string, 1: string, 2: class-string<Model>, 3: string}
     */
    private function resolve(string $reference, array $tables, string $what): array
    {
        [$alias, $column] = str_contains($reference, '.') ? explode('.', $reference, 2) : ['', $reference];
        $table = $tables[$alias] ?? throw new InvalidDatasetDefinition("{$what}: {$alias} bukan alias join dataset.");
        $type = $table['columns'][$column] ?? throw new InvalidDatasetDefinition("kolom {$column} tidak ada di {$table['label']} ({$what}).");

        return [$table['table'].'.'.$column, $type, $table['model'], $column];
    }

    /**
     * Field baru dari kolom tabel dasar bernama `$key`, untuk rujukan dan dimensi bersama yang kolomnya
     * tidak ditawarkan katalog filter.
     *
     * @param  array<string, array{table: string, label: string, columns: array<string, string>, model: class-string<Model>}>  $tables
     * @return array{0: FilterField, 1: ?DataClass, 2: string}
     */
    private function baseField(string $key, string $caption, array $tables): array
    {
        [$column, $type, $owner, $name] = $this->resolve($key, $tables, "field {$key}");

        return [new FilterField($key, $caption, FieldType::Reference, $column, [], $this->lookup($owner, $name)), $this->classification($owner, $name), $type];
    }

    /**
     * Klasifikasi efektif satu kolom model: timpaan kolom (`COLUMN_CLASSIFICATION`), kolom jejak milik
     * platform, lalu bawaan tabel (`#[DataClassification]`, dibaca juga dari kelas induk). Urutan yang
     * sama dengan `DataClassificationBoundaryTest`.
     *
     * @param  class-string<Model>  $model
     */
    private function classification(string $model, string $column): ?DataClass
    {
        $columns = $this->constant($model, 'COLUMN_CLASSIFICATION');
        $value = $columns[$column] ?? AuditColumns::COLUMN_CLASSIFICATION[$column] ?? null;
        if ($value instanceof DataClass) {
            return $value;
        }

        for ($class = new ReflectionClass($model); $class !== false; $class = $class->getParentClass()) {
            $attributes = $class->getAttributes(DataClassification::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance()->default;
            }
        }

        return null;
    }

    /** @param class-string<Model> $model */
    private function lookup(string $model, string $column): ?string
    {
        $lookup = $this->constant($model, 'FIELD_LOOKUPS')[$column] ?? null;

        return is_string($lookup) ? $lookup : null;
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function constant(string $class, string $name): array
    {
        $constant = $class.'::'.$name;

        return defined($constant) ? (array) constant($constant) : [];
    }

    /** @return array<string, string> nama kolom => nama tipe PostgreSQL; kosong bila tabelnya tidak ada */
    private function columnsOf(Model $model): array
    {
        $columns = [];
        foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
            $columns[(string) $column['name']] = (string) $column['type_name'];
        }

        return $columns;
    }

    /**
     * Kolom query sumber beserta tipenya, dari menjalankan subquery `LIMIT 0`: tidak ada baris yang
     * dibaca, dan global scope (termasuk tenant) tidak dipasang karena yang dijalankan query dasarnya.
     * Dijalankan di transaksi yang selalu dibatalkan, supaya query sumber yang gagal tidak membatalkan
     * transaksi pemanggil.
     *
     * @param  Builder<Model>  $builder
     * @return array<string, string>
     */
    private function sourceColumns(Builder $builder): array
    {
        $query = $builder->getQuery();
        $connection = $builder->getModel()->getConnection();
        $connection->beginTransaction();

        try {
            $statement = $connection->getPdo()->prepare('select * from ('.$query->toSql().') as base limit 0');
            $statement->execute($connection->prepareBindings($query->getBindings()));
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $meta = $statement->getColumnMeta($i);
                if (is_array($meta)) {
                    $columns[(string) $meta['name']] = (string) ($meta['native_type'] ?? '');
                }
            }

            return $columns;
        } catch (PDOException $e) {
            // Kelas 42 (nama kolom, tabel, atau sintaks) adalah cacat query sumber; selebihnya, misalnya
            // database tidak terjangkau, bukan cacat dataset dan dilempar apa adanya.
            if (str_starts_with((string) ($e->errorInfo[0] ?? ''), '42')) {
                throw new InvalidDatasetDefinition('query sumber tidak dapat dijalankan: '.$e->getMessage(), 0, $e);
            }

            throw $e;
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Global scope yang masih terpasang pada query Eloquent. Laravel tidak menyediakan pembacanya, dan
     * `removedScopes()` saja tidak cukup: query dari `newModelQuery()` tidak pernah memasang scope apa pun.
     *
     * @param  Builder<Model>  $builder
     * @return array<mixed>
     */
    private function scopesOf(Builder $builder): array
    {
        $scopes = (new ReflectionProperty(Builder::class, 'scopes'))->getValue($builder);

        return is_array($scopes) ? $scopes : [];
    }

    /**
     * @param  array<string, array{model: class-string<Model>, local: string, foreign: string, include_archived: bool}>  $joins
     */
    private function columnRef(mixed $reference, string $what, array $joins): string
    {
        if (is_string($reference) && str_contains($reference, '.')) {
            [$alias, $column] = explode('.', $reference, 2);
            if (! isset($joins[$alias])) {
                throw new InvalidDatasetDefinition("{$what}: {$alias} bukan alias join dataset.");
            }
            $this->key($column, $what);

            return $reference;
        }

        return $this->key($reference, $what);
    }

    /**
     * @param  array<string, array{model: class-string<Model>, local: string, foreign: string, include_archived: bool}>  $joins
     */
    private function optionalColumnRef(mixed $reference, string $what, array $joins): ?string
    {
        return $reference === null ? null : $this->columnRef($reference, $what, $joins);
    }

    private function key(mixed $name, string $what): string
    {
        if (! is_string($name) || ! self::isKey($name)) {
            throw new InvalidDatasetDefinition($what.' harus snake_case: huruf kecil, angka, dan garis bawah, paling panjang 64 karakter.');
        }

        return $name;
    }

    /** @return list<string> */
    private function names(mixed $names, string $what): array
    {
        if (! is_array($names)) {
            throw new InvalidDatasetDefinition($what.' tidak berbentuk benar.');
        }

        return array_values(array_map(fn (mixed $name): string => $this->key($name, $what), $names));
    }

    private function text(mixed $text, string $message): string
    {
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidDatasetDefinition($message);
        }

        return $text;
    }

    private function optionalText(mixed $text, string $message): ?string
    {
        return $text === null ? null : $this->text($text, $message);
    }
}
