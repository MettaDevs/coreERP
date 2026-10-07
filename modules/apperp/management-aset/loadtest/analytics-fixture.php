<?php

declare(strict_types=1);

use App\Platform\Modules\Contracts\TenantRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Analytics\AssetRegisterDataset;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;

const GROUP_COUNT = 12;
const SAMPLE_GROUP_COUNT = 3;
const INSERT_BATCH_SIZE = 500;

$manifestPath = $argv[1] ?? (getenv('ANALYTICS_FIXTURE_OUTPUT') ?: '/results/analytics-fixture.json');

try {
    if ($argc > 2 || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
        throw new InvalidArgumentException('Berkas manifest fixture tidak dapat dibaca.');
    }

    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Isi manifest bukan JSON yang sah.');
    }
    validateManifest($manifest);

    $corePath = getcwd();
    if (! is_file($corePath.'/artisan') || ! is_file($corePath.'/bootstrap/app.php')) {
        throw new RuntimeException('Jalankan skrip dari direktori apps/core.');
    }

    require $corePath.'/vendor/autoload.php';
    $app = require $corePath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $dataset = app(AssetRegisterDataset::class);
    $definition = $dataset->definition();
    if ($manifest['dataset'] !== $definition->code
        || ! str_starts_with($definition->code, $dataset->moduleId().'.')) {
        throw new RuntimeException('Dataset pada manifest tidak cocok dengan register aset module ini.');
    }
    $moduleId = $dataset->moduleId();
    $assetReference = $moduleId.'.aset';
    $typeReference = $moduleId.'.jenis-aset';
    $numbers = app(AssetNumberSequenceIssuer::class);

    $assetModel = new Aset;
    $groupModel = new GroupAset;
    $typeModel = new JenisAset;
    $assetClass = $assetModel::class;
    $legalEntityColumn = 'legal_entity_id';
    $orgUnitColumn = 'responsible_org_unit_id';
    $groupColumn = 'group_aset_id';
    $typeColumn = 'jenis_aset_id';
    $dateColumn = 'acquired_on';
    $valueColumn = 'acquisition_value';
    $currencyColumn = 'currency_code';
    $columns = array_fill_keys(
        $assetModel->getConnection()->getSchemaBuilder()->getColumnListing($assetModel->getTable()),
        true,
    );
    $requiredColumns = [
        $assetModel->getKeyName(), 'tenant_id', 'creation_key', 'kode', 'nama',
        $legalEntityColumn, $orgUnitColumn, $groupColumn, $typeColumn,
        $dateColumn, $valueColumn, $currencyColumn,
    ];
    if (array_diff($requiredColumns, array_keys($columns)) !== []) {
        throw new RuntimeException('Skema aset tidak memuat semua kolom yang didaftarkan dataset.');
    }

    $tenantRunner = app(TenantRunner::class);
    $fixtureHash = substr(hash('sha256', $manifest['fixture_id']), 0, 16);
    $tenantTotal = count($manifest['tenants']);

    foreach ($manifest['tenants'] as $tenantPosition => &$tenant) {
        $result = $tenantRunner->runFor($tenant['tenant_id'], function () use (
            $assetClass,
            $assetModel,
            $groupModel,
            $typeModel,
            $numbers,
            $assetReference,
            $typeReference,
            $tenant,
            $fixtureHash,
            $columns,
            $legalEntityColumn,
            $orgUnitColumn,
            $groupColumn,
            $typeColumn,
            $dateColumn,
            $valueColumn,
            $currencyColumn,
        ): array {
            return $assetModel->getConnection()->transaction(function () use (
                $assetClass,
                $assetModel,
                $groupModel,
                $typeModel,
                $numbers,
                $assetReference,
                $typeReference,
                $tenant,
                $fixtureHash,
                $columns,
                $legalEntityColumn,
                $orgUnitColumn,
                $groupColumn,
                $typeColumn,
                $dateColumn,
                $valueColumn,
                $currencyColumn,
            ): array {
                $tenantId = $tenant['tenant_id'];
                $tenantIndex = $tenant['index'];
                $groupIds = [];

                for ($groupIndex = 0; $groupIndex < GROUP_COUNT; $groupIndex++) {
                    $master = ensureMaster(
                        $groupModel,
                        $tenantId,
                        sprintf('analytics-fixture-%s-tenant-%d-group-%d', $fixtureHash, $tenantIndex, $groupIndex),
                        sprintf('AG%sT%04dG%02d', $fixtureHash, $tenantIndex, $groupIndex),
                        sprintf('Kelompok uji %02d', $groupIndex + 1),
                    );
                    $groupIds[] = (string) $master->getKey();
                }

                $typeCreationKey = sprintf('analytics-fixture-%s-tenant-%d-type', $fixtureHash, $tenantIndex);
                $type = ensureMaster(
                    $typeModel,
                    $tenantId,
                    $typeCreationKey,
                    '',
                    'Jenis uji aset',
                    fn (): string => $numbers->issue($typeReference, $tenantId, 'jenis-aset:'.$typeCreationKey),
                );
                $typeId = (string) $type->getKey();
                $assetCount = $tenant['asset_count'];
                $assetKeyPrefix = sprintf('analytics-fixture-%s-tenant-%d-asset-', $fixtureHash, $tenantIndex);
                $primaryKey = $assetModel->getKeyName();
                $existingRows = $assetClass::withTrashed()
                    ->where('tenant_id', $tenantId)
                    ->where('creation_key', 'like', $assetKeyPrefix.'%')
                    ->get([$primaryKey, 'creation_key']);
                $existingIds = [];

                foreach ($existingRows as $row) {
                    $suffix = substr((string) $row->creation_key, strlen($assetKeyPrefix));
                    if (preg_match('/^\d{5}$/D', $suffix) !== 1 || (int) $suffix >= $assetCount) {
                        throw new RuntimeException('Jumlah aset fixture berubah; gunakan fixture_id baru.');
                    }
                    $existingIds[(int) $suffix] = (string) $row->getAttribute($primaryKey);
                }

                $assetClass::withTrashed()
                    ->where('tenant_id', $tenantId)
                    ->where('creation_key', 'like', $assetKeyPrefix.'%')
                    ->whereNotNull('deleted_at')
                    ->update(['deleted_at' => null, 'updated_at' => now()]);

                $sampleIndexes = sampleIndexes($assetCount);
                $sampleSet = array_fill_keys($sampleIndexes, true);
                $sampleIds = [];
                $batch = [];
                $timestamp = now();

                for ($assetIndex = 0; $assetIndex < $assetCount; $assetIndex++) {
                    if (isset($existingIds[$assetIndex])) {
                        if (isset($sampleSet[$assetIndex])) {
                            $sampleIds[$assetIndex] = $existingIds[$assetIndex];
                        }

                        continue;
                    }

                    $id = (string) Str::ulid();
                    $groupIndex = $assetIndex % GROUP_COUNT;
                    $groupCycle = intdiv($assetIndex, GROUP_COUNT);
                    $orgUnitIndex = (intdiv($assetIndex, 2) + $groupCycle) % count($tenant['org_unit_ids']);
                    $date = acquiredDate($assetIndex);
                    $creationKey = $assetKeyPrefix.sprintf('%05d', $assetIndex);
                    $legalEntityId = $tenant['legal_entity_ids'][$groupCycle % 2];
                    $code = $numbers->issue($assetReference, $tenantId, 'aset:'.$creationKey, $legalEntityId);
                    $row = [
                        $primaryKey => $id,
                        'tenant_id' => $tenantId,
                        'creation_key' => $creationKey,
                        'kode' => $code,
                        'nama' => sprintf('Aset uji %05d', $assetIndex + 1),
                        $legalEntityColumn => $legalEntityId,
                        $orgUnitColumn => $tenant['org_unit_ids'][$orgUnitIndex],
                        $groupColumn => $groupIds[$groupIndex],
                        $typeColumn => $typeId,
                        $dateColumn => $date,
                        $valueColumn => sprintf('%d.00', 1000000 + (($assetIndex * 173) % 50000000)),
                        $currencyColumn => ($groupCycle + intdiv($assetIndex, 2)) % 10 === 0 ? 'USD' : 'IDR',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];

                    if (isset($columns['placed_in_service_on'])) {
                        $row['placed_in_service_on'] = $date;
                    }
                    if (isset($columns['lifecycle_state'])) {
                        $row['lifecycle_state'] = 'received';
                    }
                    if (isset($columns['financial_dimension_org_unit_id'])) {
                        $row['financial_dimension_org_unit_id'] = $tenant['org_unit_ids'][($orgUnitIndex + 1) % count($tenant['org_unit_ids'])];
                    }

                    $batch[] = $row;
                    if (isset($sampleSet[$assetIndex])) {
                        $sampleIds[$assetIndex] = $id;
                    }
                    if (count($batch) >= INSERT_BATCH_SIZE) {
                        $assetModel->newQuery()->insert($batch);
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    $assetModel->newQuery()->insert($batch);
                }

                $activeCount = $assetClass::query()
                    ->where('tenant_id', $tenantId)
                    ->where('creation_key', 'like', $assetKeyPrefix.'%')
                    ->count();
                if ($activeCount !== $assetCount) {
                    throw new RuntimeException('Jumlah aset aktif tidak sama dengan manifest fixture.');
                }

                ksort($sampleIds);

                return [
                    'group_ids' => $groupIds,
                    'type_id' => $typeId,
                    'sample_asset_ids' => array_values($sampleIds),
                    'asset_count' => $activeCount,
                ];
            });
        });

        $tenant = [...$tenant, ...$result];
        fwrite(STDOUT, sprintf("Tenant %d/%d: %d aset siap\n", $tenantPosition + 1, $tenantTotal, $result['asset_count']));
    }
    unset($tenant);

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    if (file_put_contents($manifestPath, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Manifest fixture tidak dapat diperbarui.');
    }

    fwrite(STDOUT, sprintf("Fixture register aset siap: %d tenant, %s\n", $tenantTotal, $manifestPath));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fixture register aset gagal: '.$exception->getMessage().PHP_EOL);
    exit(1);
}

function validateManifest(mixed $manifest): void
{
    if (! is_array($manifest)
        || ! is_string($manifest['fixture_id'] ?? null)
        || preg_match('/^[A-Za-z0-9-]{1,80}$/D', $manifest['fixture_id']) !== 1
        || ! is_string($manifest['dataset'] ?? null)
        || $manifest['dataset'] === ''
        || ! is_array($manifest['tenants'] ?? null)
        || ! array_is_list($manifest['tenants'])
        || count($manifest['tenants']) < 100
        || $manifest['tenants'] === []) {
        throw new InvalidArgumentException('Bentuk manifest fixture tidak sah.');
    }

    $tenantIds = [];
    $tenantIndexes = [];
    foreach ($manifest['tenants'] as $tenant) {
        if (! is_array($tenant)
            || ! is_int($tenant['index'] ?? null)
            || $tenant['index'] < 0
            || ! is_string($tenant['tenant_id'] ?? null)
            || ! isUlid($tenant['tenant_id'])
            || isset($tenantIds[$tenant['tenant_id']])
            || isset($tenantIndexes[$tenant['index']])
            || ! is_int($tenant['asset_count'] ?? null)
            || $tenant['asset_count'] < 5000
            || $tenant['asset_count'] > 20000) {
            throw new InvalidArgumentException('Tenant atau jumlah aset pada manifest tidak sah.');
        }

        $tenantIds[$tenant['tenant_id']] = true;
        $tenantIndexes[$tenant['index']] = true;
        validateIds($tenant['legal_entity_ids'] ?? null, 2, 'legal entity');
        validateIds($tenant['org_unit_ids'] ?? null, 8, 'unit kerja');
        if (array_intersect($tenant['legal_entity_ids'], $tenant['org_unit_ids']) !== []) {
            throw new InvalidArgumentException('ID legal entity dan unit kerja tidak boleh sama.');
        }
    }
}

function validateIds(mixed $ids, int $expected, string $label): void
{
    if (! is_array($ids) || ! array_is_list($ids) || count($ids) !== $expected) {
        throw new InvalidArgumentException("Manifest harus memuat tepat {$expected} ID {$label} per tenant.");
    }

    foreach ($ids as $id) {
        if (! is_string($id) || ! isUlid($id)) {
            throw new InvalidArgumentException("ID {$label} pada manifest tidak sah.");
        }
    }

    if (count(array_unique($ids)) !== $expected) {
        throw new InvalidArgumentException("ID {$label} pada manifest harus unik.");
    }
}

function isUlid(string $value): bool
{
    return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/iD', $value) === 1;
}

function ensureMaster(Model $prototype, string $tenantId, string $creationKey, string $code, string $name, ?Closure $codeIssuer = null): Model
{
    $class = $prototype::class;
    $master = $class::withTrashed()
        ->where('tenant_id', $tenantId)
        ->where('creation_key', $creationKey)
        ->first();

    if ($master !== null) {
        if ($master->trashed()) {
            $master->restore();
        }
        if (! (bool) $master->getAttribute('aktif')) {
            $master->setAttribute('aktif', true);
            $master->save();
        }

        return $master;
    }

    return $class::query()->create([
        'tenant_id' => $tenantId,
        'creation_key' => $creationKey,
        'kode' => $codeIssuer === null ? $code : $codeIssuer(),
        'nama' => $name,
        'aktif' => true,
    ]);
}

/** @return list<int> */
function sampleIndexes(int $assetCount): array
{
    $indexes = [];
    for ($groupIndex = 0; $groupIndex < GROUP_COUNT; $groupIndex++) {
        $groupSize = intdiv($assetCount - 1 - $groupIndex, GROUP_COUNT) + 1;
        for ($sampleIndex = 0; $sampleIndex < min(SAMPLE_GROUP_COUNT, $groupSize); $sampleIndex++) {
            $withinGroup = intdiv($sampleIndex * $groupSize, min(SAMPLE_GROUP_COUNT, $groupSize));
            $indexes[] = $groupIndex + GROUP_COUNT * $withinGroup;
        }
    }

    return $indexes;
}

function acquiredDate(int $assetIndex): string
{
    $monthOffset = intdiv($assetIndex, GROUP_COUNT) % 36;
    $month = (new DateTimeImmutable('2023-10-01', new DateTimeZone('UTC')))->modify("+{$monthOffset} months");

    return match (intdiv($assetIndex, GROUP_COUNT) % 3) {
        0 => $month->format('Y-m-01'),
        1 => $month->format('Y-m-t'),
        default => $month->format('Y-m-15'),
    };
}
