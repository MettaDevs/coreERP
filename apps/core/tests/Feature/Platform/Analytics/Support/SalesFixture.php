<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics\Support;

use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Modules\Models\ModuleInstallation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bahan uji area 9 di atas dataset `contoh-a.penjualan`: tenant, unit kerja, module contoh terpasang, dan
 * penjualan, lalu query lewat `RunQuery` utuh — pemeriksaan hak, cache, jatah, dan log ikut berjalan.
 *
 * Module contoh dipasang dengan menulis catatan pemasangannya langsung, seperti `PersonalDataGateTest`;
 * tabelnya dibuat migration module contoh.
 */
trait SalesFixture
{
    protected const SALES = 'contoh-a.penjualan';

    protected function migrateSalesModule(): void
    {
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 4).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /** Tenant baru dengan module contoh terpasang. */
    protected function salesTenant(string $name): string
    {
        $client = (string) Str::ulid();
        $tenant = (string) Str::ulid();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
        DB::table('clients')->insert(['id' => $client, 'legal_name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenant, 'client_id' => $client, 'name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('core_module_installations')->insert([
            'tenant_id' => $tenant, 'module_id' => 'contoh-a', 'version' => '0.1.0',
            'status' => ModuleInstallation::STATUS_INSTALLED, 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $tenant;
    }

    protected function sale(string $tenant, string $legalEntity, string $unit, string $value, string $date = '2026-09-15', ?string $buyer = null, string $status = 'terbit'): void
    {
        DB::table('contoh_a_tr_penjualan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'barang_id' => (string) Str::ulid(),
            'legal_entity_id' => $legalEntity, 'org_unit_id' => $unit, 'status' => $status, 'nilai' => $value,
            'currency_code' => 'IDR', 'tanggal' => $date, 'nama_pembeli' => $buyer, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $input */
    protected function salesQuery(array $input = []): AnalyticsQuery
    {
        return (new QueryNormalizer)->normalize((new QueryParser)->parse([...['dataset' => self::SALES, 'measures' => ['count']], ...$input]));
    }

    /** @param array<string, mixed> $input */
    protected function analyse(TestPrincipal $principal, array $input = [], ?int $ttl = null, bool $refresh = false, string $source = QueryLog::SOURCE_EXPLORE): ResultSet
    {
        return app(RunQuery::class)->handle($principal, $this->salesQuery($input), cacheTtl: $ttl, refresh: $refresh, source: $source);
    }

    protected function salesDataset(): CompiledDataset
    {
        $dataset = app(DatasetRegistry::class)->find(self::SALES);
        $this->assertNotNull($dataset, 'Dataset bahan uji tidak terdaftar; test ini akan lulus tanpa menguji apa pun.');

        return $dataset;
    }

    /**
     * Hibah satu unit pada satu legal entity, bentuk `DataPolicyAccessResolver::resolve()`.
     *
     * @return array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}
     */
    protected static function grant(string $legalEntity, string $unit): array
    {
        return ['all' => false, 'scope_grants' => [['legal_entity_id' => $legalEntity, 'operating_unit_ids' => [$unit]]]];
    }

    /** Jumlah penjualan di baris hasil pertama. */
    protected static function counted(ResultSet $result): int
    {
        return (int) ($result->rows[0]['count'] ?? -1);
    }
}
