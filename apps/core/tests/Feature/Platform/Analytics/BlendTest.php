<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Dashboards\WidgetDefinition;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Http\Presenters\DashboardPresenter;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\Blend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Platform\Analytics\Support\SalesFixture;
use Tests\Feature\Platform\Analytics\Support\TestPrincipal;
use Tests\TestCase;

class BlendTest extends TestCase
{
    use RefreshDatabase;
    use SalesFixture;

    private string $tenant;

    private string $legalEntity;

    private string $sharedUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSalesModule();
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-b/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->tenant = $this->salesTenant('Tenant uji gabungan');
        DB::table('core_module_installations')->insert([
            'tenant_id' => $this->tenant, 'module_id' => 'contoh-b', 'version' => '0.1.0',
            'status' => 'installed', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->legalEntity = (string) Str::ulid();
        $this->sharedUnit = (string) Str::ulid();
    }

    public function test_it_full_outer_merges_colliding_measure_keys_by_the_shared_dimension(): void
    {
        $otherA = (string) Str::ulid();
        $otherB = (string) Str::ulid();
        $this->sale($this->tenant, $this->legalEntity, $this->sharedUnit, '10.00');
        $this->sale($this->tenant, $this->legalEntity, $this->sharedUnit, '20.00');
        $this->sale($this->tenant, $this->legalEntity, $otherA, '30.00');
        $this->order($this->sharedUnit, 'IDR', '40.00');
        $this->order($otherB, 'IDR', '50.00');
        $principal = $this->principal();

        $result = app(Blend::class)->handle($principal, $this->queries(['count'], ['count']), cacheTtl: 0)->toArray();

        $this->assertCount(3, $result['rows']);
        $rows = collect($result['rows'])->keyBy('org_unit_id');
        $this->assertSame(2, $rows[$this->sharedUnit]['contoh-a.penjualan.count']);
        $this->assertSame(1, $rows[$this->sharedUnit]['contoh-b.orders.count']);
        $this->assertSame(1, $rows[$otherA]['contoh-a.penjualan.count']);
        $this->assertNull($rows[$otherA]['contoh-b.orders.count']);
        $this->assertNull($rows[$otherB]['contoh-a.penjualan.count']);
        $this->assertSame(1, $rows[$otherB]['contoh-b.orders.count']);

        $measure = collect($result['columns'])->firstWhere('key', 'contoh-b.orders.count');
        $this->assertSame('contoh-b.orders', $measure['dataset']);
        $this->assertSame('count', $measure['measure']);
        $this->assertSame('Jumlah pesanan', $measure['caption']);
        $this->assertSame('number', $measure['format']);
        $this->assertNull($measure['currency']);
    }

    public function test_it_keeps_currency_groups_separate_and_namespaces_money_metadata(): void
    {
        $this->saleInCurrency($this->sharedUnit, 'IDR', '100.00');
        $this->saleInCurrency($this->sharedUnit, 'USD', '200.00');
        $this->order($this->sharedUnit, 'IDR', '300.00');
        $this->order($this->sharedUnit, 'USD', '400.00');

        $result = app(Blend::class)->handle($this->principal(), $this->queries(['nilai'], ['amount']), cacheTtl: 0)->toArray();

        $this->assertCount(2, $result['rows']);
        $rows = collect($result['rows'])->keyBy('__currency');
        $this->assertSame('100.00', $rows['IDR']['contoh-a.penjualan.nilai']);
        $this->assertSame('300.00', $rows['IDR']['contoh-b.orders.amount']);
        $this->assertSame('200.00', $rows['USD']['contoh-a.penjualan.nilai']);
        $this->assertSame('400.00', $rows['USD']['contoh-b.orders.amount']);

        $measure = collect($result['columns'])->firstWhere('key', 'contoh-a.penjualan.nilai');
        $this->assertSame('contoh-a.penjualan', $measure['dataset']);
        $this->assertSame('nilai', $measure['measure']);
        $this->assertSame('money', $measure['format']);
        $this->assertSame('currency_code', $measure['currency']);
        $this->assertSame('__currency', $measure['currency_key']);
    }

    public function test_it_checks_installation_and_permission_for_each_source_before_running_any_query(): void
    {
        DB::table('core_module_installations')->where('tenant_id', $this->tenant)->where('module_id', 'contoh-b')->update(['status' => 'disabled']);

        try {
            app(Blend::class)->handle($this->principal(), $this->queries(['count'], ['count']), cacheTtl: 0);
            $this->fail('Data dari module yang tidak terpasang harus ditolak.');
        } catch (AnalyticsQueryException $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('queries.1.dataset', $exception->field);
        }

        DB::table('core_module_installations')->where('tenant_id', $this->tenant)->where('module_id', 'contoh-b')->update(['status' => 'installed']);
        $principal = $this->principal(permissions: ['contoh-b.contoh-b.orders.read' => false]);

        try {
            app(Blend::class)->handle($principal, $this->queries(['count'], ['count']), cacheTtl: 0);
            $this->fail('Permission dataset kedua harus diperiksa secara terpisah.');
        } catch (AnalyticsQueryException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('queries.1.dataset', $exception->field);
        }
    }

    public function test_each_source_applies_its_own_data_policy(): void
    {
        $this->sale($this->tenant, $this->legalEntity, $this->sharedUnit, '10.00');
        $this->order($this->sharedUnit, 'IDR', '20.00');
        $none = ['all' => false, 'scope_grants' => []];
        $all = ['all' => true, 'scope_grants' => []];

        $aOnly = app(Blend::class)->handle($this->principal(policyScopes: [
            'contoh-a.penjualan-unit' => $all,
            'contoh-b.orders-scope' => $none,
        ]), $this->queries(['count'], ['count']), cacheTtl: 0)->toArray();
        $this->assertCount(1, $aOnly['rows']);
        $this->assertSame(1, $aOnly['rows'][0]['contoh-a.penjualan.count']);
        $this->assertNull($aOnly['rows'][0]['contoh-b.orders.count']);

        $bOnly = app(Blend::class)->handle($this->principal(policyScopes: [
            'contoh-a.penjualan-unit' => $none,
            'contoh-b.orders-scope' => $all,
        ]), $this->queries(['count'], ['count']), cacheTtl: 0)->toArray();
        $this->assertCount(1, $bOnly['rows']);
        $this->assertNull($bOnly['rows'][0]['contoh-a.penjualan.count']);
        $this->assertSame(1, $bOnly['rows'][0]['contoh-b.orders.count']);
    }

    public function test_the_same_dataset_cannot_be_used_twice_under_the_same_output_keys(): void
    {
        $queries = $this->queries(['count'], ['count']);
        $queries['queries'][1]['dataset'] = self::SALES;

        try {
            app(Blend::class)->validate($this->principal(), $queries);
            $this->fail('Dua sumber yang sama akan menimpa kunci measure gabungan.');
        } catch (AnalyticsQueryException $exception) {
            $this->assertSame('analytics.invalid_query', $exception->errorCode);
            $this->assertSame('queries.1.dataset', $exception->field);
        }
    }

    public function test_blends_reject_formula_comparison_and_percent_of_total_until_their_columns_are_merged(): void
    {
        $cases = [];

        $formula = $this->queries(['count', 'doubled'], ['count']);
        $formula['queries'][0]['formulas'] = [['key' => 'doubled', 'expression' => '[count] * 2']];
        $cases[] = [$formula, 'queries.0.formulas'];

        $comparison = $this->queries(['count'], ['count']);
        $comparison['queries'][0]['time_range'] = ['range' => '01/01/2026..31/01/2026'];
        $comparison['queries'][0]['compare'] = 'previous_period';
        $cases[] = [$comparison, 'queries.0.compare'];

        $percent = $this->queries(['count'], ['count']);
        $percent['queries'][0]['percent_of_total'] = ['count'];
        $cases[] = [$percent, 'queries.0.percent_of_total'];

        foreach ($cases as [$input, $field]) {
            try {
                app(Blend::class)->validate($this->principal(), $input);
                $this->fail('Kolom turunan yang belum digabung tidak boleh diabaikan diam-diam.');
            } catch (AnalyticsQueryException $exception) {
                $this->assertSame('analytics.invalid_query', $exception->errorCode);
                $this->assertSame($field, $exception->field);
            }
        }
    }

    public function test_each_source_keeps_its_own_row_limit_and_truncation_metadata(): void
    {
        $units = [(string) Str::ulid(), (string) Str::ulid(), (string) Str::ulid()];
        foreach ($units as $unit) {
            $this->sale($this->tenant, $this->legalEntity, $unit, '10.00');
            $this->order($unit, 'IDR', '20.00');
        }
        $queries = $this->queries(['count'], ['count']);
        $queries['queries'][0]['limit'] = 1;
        $queries['queries'][1]['limit'] = 2;

        $result = app(Blend::class)->handle($this->principal(rowLimit: 5), $queries, cacheTtl: 0)->toArray();

        $this->assertSame([1, 2], array_column($result['meta']['sources'], 'row_limit'));
        $this->assertSame([true, true], array_column($result['meta']['sources'], 'truncated'));
        $this->assertCount(2, $result['rows']);
    }

    public function test_widget_definition_validates_and_compacts_a_blend_query(): void
    {
        $query = $this->queries(['count'], ['count']);
        $definition = app(WidgetDefinition::class)->validate($this->principal(), 'blend', $query, [
            'columns' => ['org_unit_id', 'contoh-a.penjualan.count', 'contoh-b.orders.count'],
        ]);

        $this->assertNull($definition['dataset_code']);
        $this->assertNull($definition['dataset_version']);
        $datasets = app(DatasetRegistry::class);
        $datasetA = $datasets->find(self::SALES);
        $datasetB = $datasets->find('contoh-b.orders');
        $this->assertNotNull($datasetA);
        $this->assertNotNull($datasetB);
        $this->assertSame([
            'queries' => [
                ['query' => $query['queries'][0], 'dataset_version' => $datasetA->version],
                ['query' => $query['queries'][1], 'dataset_version' => $datasetB->version],
            ],
        ], $definition['query']);
        $read = app(Blend::class)->readStorage($definition['query']);
        $this->assertSame($query, $read['query']);
        $this->assertSame([[], []], $read['maps']);
        $this->assertSame([], $read['missing']);
        $this->assertSame(['org_unit_id', 'contoh-a.penjualan.count', 'contoh-b.orders.count'], $definition['visual']['columns']);

        $widget = new Widget([
            'tenant_id' => $this->tenant,
            'type' => 'blend',
            'dataset_code' => null,
            'dataset_version' => null,
            'query' => $definition['query'],
            'visual' => $definition['visual'],
        ]);
        $presented = app(DashboardPresenter::class)->widget($widget, ['contoh-a']);
        $this->assertSame('dataset_unavailable', $presented['status']);
    }

    /**
     * @param  list<string>  $measuresA
     * @param  list<string>  $measuresB
     * @return array{queries: list<array<string, mixed>>}
     */
    private function queries(array $measuresA, array $measuresB): array
    {
        return ['queries' => [
            ['dataset' => self::SALES, 'dimensions' => ['org_unit_id'], 'measures' => $measuresA],
            ['dataset' => 'contoh-b.orders', 'dimensions' => ['org_unit_id'], 'measures' => $measuresB],
        ]];
    }

    /** @param array<string, array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}> $policyScopes
     * @param  array<string, bool>  $permissions
     */
    private function principal(array $policyScopes = [], array $permissions = [], int $rowLimit = 5000): TestPrincipal
    {
        return new TestPrincipal(
            $this->tenant,
            timezone: 'UTC',
            rowLimit: $rowLimit,
            permissions: $permissions,
            policyScopes: $policyScopes,
        );
    }

    private function order(string $unit, string $currency, string $amount): void
    {
        DB::table('contoh_b_tr_orders')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenant,
            'legal_entity_id' => $this->legalEntity, 'org_unit_id' => $unit,
            'currency_code' => $currency, 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function saleInCurrency(string $unit, string $currency, string $amount): void
    {
        DB::table('contoh_a_tr_penjualan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenant, 'barang_id' => (string) Str::ulid(),
            'legal_entity_id' => $this->legalEntity, 'org_unit_id' => $unit,
            'status' => 'terbit', 'nilai' => $amount, 'currency_code' => $currency,
            'tanggal' => '2026-09-15', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
