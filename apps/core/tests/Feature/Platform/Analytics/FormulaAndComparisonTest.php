<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\FiscalYearRange;
use App\Platform\Analytics\Query\LabelResolver;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Modules\Contracts\TenantRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rumus, persen terhadap total, dan perbandingan periode (area 13) di atas dataset bahan uji `contoh-a.penjualan`,
 * dijalankan sampai database: angka rumus termasuk bagi nol, urutan dan top-N atas rumus, warisan mata uang,
 * persen terhadap total per mata uang yang tidak terpengaruh top-N, perbandingan periode dengan kelompok yang
 * hanya ada di periode lalu, deret waktu tahun ini terhadap tahun lalu termasuk bulan kosong, batas tahun, tahun
 * kabisat, zona waktu pengguna, dan total per mata uang beserta selisihnya.
 *
 * "Sekarang" dibekukan pada 20 Oktober 2026 pukul 10.00 WITA. Query dijalankan lewat langkah yang sama dengan
 * `RunQuery` tanpa pemeriksaan pemasangan module, seperti `QueryCompilerTest`.
 */
class FormulaAndComparisonTest extends TestCase
{
    use RefreshDatabase;

    private const SALES = 'contoh-a.penjualan';

    private string $tenantA;

    private string $legalEntity;

    private string $unitA;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Makassar'));

        $this->tenantA = $this->tenant('Tenant rumus A');
        $tenantB = $this->tenant('Tenant rumus B');
        $this->legalEntity = $this->organization($this->tenantA, 'legal_entity', 'CV Rumus');
        $this->unitA = $this->organization($this->tenantA, 'operating_unit', 'Unit Rumus');

        $this->sale($this->tenantA, 'terbit', '100.00', 'IDR', '2026-09-10');
        $this->sale($this->tenantA, 'draf', '50.00', 'IDR', '2026-09-20');
        $this->sale($this->tenantA, 'terbit', '200.00', 'IDR', '2026-10-05');
        $this->sale($this->tenantA, 'terbit', '10.00', 'USD', '2026-10-06');
        $this->sale($this->tenantA, 'terbit', '80.00', 'IDR', '2025-10-15');
        $this->sale($this->tenantA, 'terbit', '30.00', 'IDR', '2025-12-20');
        $this->sale($this->tenantA, 'terbit', '40.00', 'IDR', '2026-01-15');
        // Tenant lain pada bulan yang sama tidak pernah ikut, di periode mana pun.
        $this->sale($tenantB, 'terbit', '999.00', 'IDR', '2026-10-05');
        $this->sale($tenantB, 'terbit', '999.00', 'IDR', '2026-09-05');
    }

    public function test_formulas_compute_ratios_over_aggregates_and_division_by_zero_is_empty_not_an_error(): void
    {
        $result = $this->analyse([
            'dimensions' => ['status'],
            'measures' => ['nilai', 'rata', 'persen_terbit', 'per_terbit', 'nol'],
            'filters' => ['currency_code' => 'IDR'],
            'formulas' => [
                ['key' => 'rata', 'caption' => 'Rata-rata', 'expression' => 'BAGI([nilai]; [count])', 'format' => 'money'],
                ['key' => 'persen_terbit', 'expression' => 'BAGI([terbit]; [count]) * 100', 'format' => 'percent'],
                ['key' => 'per_terbit', 'expression' => '[nilai] / [terbit]'],
                ['key' => 'nol', 'expression' => 'BAGI([nilai]; [count] - [count]; -1) + JIKA([count] >= 2; 1.000,5; 0)'],
            ],
        ]);

        $rows = $this->byKey($result, 'status');
        $this->assertSame('450.00', $rows['terbit']['nilai']);
        $this->assertEqualsWithDelta(90.0, (float) $rows['terbit']['rata'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $rows['terbit']['persen_terbit'], 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $rows['terbit']['per_terbit'], 0.0001);
        $this->assertEqualsWithDelta(999.5, (float) $rows['terbit']['nol'], 0.0001);
        // Draf tidak punya penjualan terbit: `BAGI` menjadi nol, `/` menjadi kosong, tanpa galat database.
        $this->assertEqualsWithDelta(0.0, (float) $rows['draf']['persen_terbit'], 0.0001);
        $this->assertNull($rows['draf']['per_terbit']);
        $this->assertEqualsWithDelta(-1.0, (float) $rows['draf']['nol'], 0.0001);

        $columns = $this->columns($result);
        $this->assertSame(['key' => 'rata', 'kind' => 'measure', 'caption' => 'Rata-rata', 'type' => 'number', 'format' => 'money', 'currency_key' => 'currency_code'], $columns['rata']);
        $this->assertSame(['key' => 'persen_terbit', 'kind' => 'measure', 'caption' => 'persen_terbit', 'type' => 'number', 'format' => 'percent'], $columns['persen_terbit']);
        // Rumus atas uang mewarisi pengelompokan mata uangnya, walau measure uangnya sendiri tidak dipilih.
        $this->assertArrayHasKey('currency_code', $columns);
        $this->assertTrue($columns['currency_code']['implicit'] ?? false);
    }

    public function test_sort_and_top_n_use_the_formula_with_empty_values_last(): void
    {
        $query = [
            'dimensions' => ['status'],
            'measures' => ['per_terbit'],
            'filters' => ['currency_code' => 'IDR'],
            'formulas' => [['key' => 'per_terbit', 'expression' => '[nilai] / [terbit]']],
        ];

        $descending = $this->analyse([...$query, 'sort' => [['key' => 'per_terbit', 'direction' => 'desc']]]);
        $this->assertSame(['terbit', 'draf'], array_column($descending->rows, 'status'), 'Kosong jatuh di akhir pada urutan turun.');

        $top = $this->analyse([...$query, 'measures' => ['count', 'rata'], 'formulas' => [['key' => 'rata', 'expression' => 'BAGI([nilai]; [count])']], 'sort' => [['key' => 'rata', 'direction' => 'asc']], 'limit' => 1]);
        $this->assertSame(['draf'], array_column($top->rows, 'status'), 'Top-N mengikuti nilai rumus, bukan measure pertama.');
        $this->assertTrue($top->meta['truncated']);
    }

    public function test_a_formula_over_money_never_mixes_currencies_in_rows_or_totals(): void
    {
        $result = $this->analyse([
            'measures' => ['dua_kali'],
            'formulas' => [['key' => 'dua_kali', 'expression' => '[nilai] * 2', 'format' => 'money']],
            'totals' => true,
        ]);

        $rows = $this->byKey($result, 'currency_code');
        $this->assertSame(['IDR', 'USD'], array_keys($rows));
        $this->assertEqualsWithDelta(1000.0, (float) $rows['IDR']['dua_kali'], 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $rows['USD']['dua_kali'], 0.0001);
        $this->assertSame(['IDR', 'USD'], array_column($result->totals, 'currency_code'));
        $this->assertEqualsWithDelta(1000.0, (float) $result->totals[0]['dua_kali'], 0.0001);
    }

    public function test_an_injection_attempt_is_refused_before_any_sql_and_the_table_survives(): void
    {
        foreach (['[count]); drop table contoh_a_tr_penjualan; --', "[count]'; delete from contoh_a_tr_penjualan; --", 'pg_sleep(5)'] as $expression) {
            try {
                $this->analyse(['measures' => ['x'], 'formulas' => [['key' => 'x', 'expression' => $expression]]]);
                $this->fail("Rumus `{$expression}` seharusnya ditolak.");
            } catch (AnalyticsQueryException $e) {
                $this->assertSame('analytics.invalid_formula', $e->errorCode);
                $this->assertSame('formulas.0.expression', $e->field);
            }
        }

        $this->assertTrue(Schema::hasTable('contoh_a_tr_penjualan'));
        $this->assertSame(9, DB::table('contoh_a_tr_penjualan')->count());
    }

    public function test_percent_of_total_is_per_currency_and_counts_every_group_not_only_the_top_n(): void
    {
        $result = $this->analyse([
            'dimensions' => ['status'],
            'measures' => ['nilai', 'count'],
            'percent_of_total' => ['nilai', 'count'],
            'sort' => [['key' => 'count', 'direction' => 'desc']],
            'totals' => true,
        ]);

        $rows = [];
        foreach ($result->rows as $row) {
            $rows[$row['status'].'/'.$row['currency_code']] = $row;
        }
        // Uang per mata uang: 450 dan 50 dari 500 rupiah, 10 dari 10 dolar.
        $this->assertEqualsWithDelta(90.0, (float) $rows['terbit/IDR']['nilai__percent_of_total'], 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $rows['draf/IDR']['nilai__percent_of_total'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $rows['terbit/USD']['nilai__percent_of_total'], 0.0001);
        // Hitungan tidak bermata uang: 5, 1, dan 1 dari 7 penjualan.
        $this->assertEqualsWithDelta(500 / 7, (float) $rows['terbit/IDR']['count__percent_of_total'], 0.0001);
        $this->assertEqualsWithDelta(100 / 7, (float) $rows['draf/IDR']['count__percent_of_total'], 0.0001);

        $columns = $this->columns($result);
        $this->assertSame(['key' => 'nilai__percent_of_total', 'kind' => 'measure', 'caption' => 'Nilai penjualan (% dari total)', 'type' => 'number', 'format' => 'percent', 'derived_from' => 'nilai', 'derivation' => 'percent_of_total'], $columns['nilai__percent_of_total']);
        $this->assertSame(['nilai', 'nilai__percent_of_total', 'count', 'count__percent_of_total'], array_values(array_filter(array_keys($columns), static fn (string $key): bool => ! in_array($key, ['status', 'status__label', 'currency_code'], true))));
        $this->assertArrayNotHasKey('nilai__percent_of_total', $result->totals[0]);

        $top = $this->analyse(['dimensions' => ['status'], 'measures' => ['count'], 'percent_of_total' => ['count'], 'limit' => 1]);
        $this->assertEqualsWithDelta(600 / 7, (float) $top->rows[0]['count__percent_of_total'], 0.0001, 'Persen dihitung terhadap semua kelompok, bukan hanya yang lolos batas.');
    }

    public function test_previous_period_keeps_groups_that_only_existed_last_period(): void
    {
        $result = $this->analyse([
            'dimensions' => ['status'],
            'measures' => ['nilai', 'count', 'rata'],
            'filters' => ['currency_code' => 'IDR'],
            'formulas' => [['key' => 'rata', 'expression' => 'BAGI([nilai]; [count])']],
            'time_range' => ['range' => '@this_month'],
            'compare' => 'previous_period',
        ]);

        $rows = $this->byKey($result, 'status');
        $this->assertSame(['terbit', 'draf'], array_keys($rows));
        $this->assertSame(['nilai' => '200.00', 'nilai__previous' => '100.00', 'nilai__change' => '100.00'], array_intersect_key($rows['terbit'], array_flip(['nilai', 'nilai__previous', 'nilai__change'])));
        $this->assertEqualsWithDelta(100.0, (float) $rows['terbit']['nilai__change_pct'], 0.0001);
        $this->assertSame(1, $rows['terbit']['count']);
        $this->assertSame(1, $rows['terbit']['count__previous']);
        $this->assertSame(0, $rows['terbit']['count__change']);
        $this->assertEqualsWithDelta(0.0, (float) $rows['terbit']['count__change_pct'], 0.0001);
        $this->assertEqualsWithDelta(200.0, (float) $rows['terbit']['rata'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $rows['terbit']['rata__previous'], 0.0001);

        // Draf hanya ada bulan lalu: nilainya sekarang nol, turun seratus persen; rumusnya tidak dapat dihitung.
        $this->assertSame(['nilai' => '0', 'nilai__previous' => '50.00', 'nilai__change' => '-50.00'], array_intersect_key($rows['draf'], array_flip(['nilai', 'nilai__previous', 'nilai__change'])));
        $this->assertEqualsWithDelta(-100.0, (float) $rows['draf']['nilai__change_pct'], 0.0001);
        $this->assertSame(0, $rows['draf']['count']);
        $this->assertNull($rows['draf']['rata']);
        $this->assertSame('Draf', $rows['draf']['status__label']);

        $columns = $this->columns($result);
        $this->assertSame(['key' => 'nilai__previous', 'kind' => 'measure', 'caption' => 'Nilai penjualan (periode sebelumnya)', 'type' => 'number', 'format' => 'money', 'currency_key' => 'currency_code', 'derived_from' => 'nilai', 'derivation' => 'previous'], $columns['nilai__previous']);
        $this->assertSame(['key' => 'count__change_pct', 'kind' => 'measure', 'caption' => 'Jumlah penjualan (perubahan % dari periode sebelumnya)', 'type' => 'number', 'format' => 'percent', 'derived_from' => 'count', 'derivation' => 'change_pct'], $columns['count__change_pct']);
    }

    public function test_this_year_against_last_year_by_month_aligns_months_and_fills_empty_ones(): void
    {
        $result = $this->analyse([
            'dimensions' => [['field' => 'tanggal', 'granularity' => 'month']],
            'measures' => ['nilai'],
            'filters' => ['currency_code' => 'IDR'],
            'time_range' => ['range' => '@this_year'],
            'compare' => 'previous_year',
        ]);

        $rows = $this->byKey($result, 'tanggal');
        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'], array_keys($rows));

        $this->assertSame(['40.00', '0', '40.00'], [$rows['2026-01-01']['nilai'], $rows['2026-01-01']['nilai__previous'], $rows['2026-01-01']['nilai__change']]);
        $this->assertNull($rows['2026-01-01']['nilai__change_pct'], 'Persen dari nol kosong, bukan tak hingga.');
        // Oktober 2025 jatuh di ember Oktober 2026.
        $this->assertSame(['200.00', '80.00', '120.00'], [$rows['2026-10-01']['nilai'], $rows['2026-10-01']['nilai__previous'], $rows['2026-10-01']['nilai__change']]);
        $this->assertEqualsWithDelta(150.0, (float) $rows['2026-10-01']['nilai__change_pct'], 0.0001);
        // Desember tahun ini belum ada penjualan, tahun lalu ada: garis tahun lalu tetap tergambar.
        $this->assertSame(['0', '30.00', '-30.00'], [$rows['2026-12-01']['nilai'], $rows['2026-12-01']['nilai__previous'], $rows['2026-12-01']['nilai__change']]);
        // Bulan tanpa penjualan di kedua tahun diisi nol, dan persennya kosong.
        $this->assertSame(['0', '0', '0', null], [$rows['2026-02-01']['nilai'], $rows['2026-02-01']['nilai__previous'], $rows['2026-02-01']['nilai__change'], $rows['2026-02-01']['nilai__change_pct']]);
    }

    public function test_previous_period_across_the_year_boundary_and_into_a_leap_february(): void
    {
        $january = $this->analyse(['measures' => ['nilai'], 'filters' => ['currency_code' => 'IDR'], 'time_range' => ['range' => '01/01/2026..31/01/2026'], 'compare' => 'previous_period']);
        $this->assertSame(['40.00', '30.00', '10.00'], [$january->rows[0]['nilai'], $january->rows[0]['nilai__previous'], $january->rows[0]['nilai__change']]);
        $this->assertEqualsWithDelta(100 / 3, (float) $january->rows[0]['nilai__change_pct'], 0.0001);

        // Maret 2028 dibandingkan dengan Februari 2028 sampai tanggal 29.
        $this->sale($this->tenantA, 'terbit', '7.00', 'IDR', '2028-02-29');
        $this->sale($this->tenantA, 'terbit', '3.00', 'IDR', '2028-02-01');
        $this->sale($this->tenantA, 'terbit', '5.00', 'IDR', '2028-03-15');
        $march = $this->analyse(['measures' => ['nilai'], 'time_range' => ['range' => '01/03/2028..31/03/2028'], 'compare' => 'previous_period']);
        $this->assertSame(['5.00', '10.00'], [$march->rows[0]['nilai'], $march->rows[0]['nilai__previous']]);
    }

    public function test_the_comparison_buckets_follow_the_user_time_zone(): void
    {
        // 30 September 16.30 UTC sudah 1 Oktober di Makassar; setahun sebelumnya juga.
        $this->sale($this->tenantA, 'terbit', '1.00', 'IDR', '2026-10-01', paidAt: '2026-09-30 16:30:00+00');
        $this->sale($this->tenantA, 'terbit', '2.00', 'IDR', '2025-10-01', paidAt: '2025-09-30 16:30:00+00');

        $query = [
            'dimensions' => [['field' => 'dibayar_pada', 'granularity' => 'month']],
            'measures' => ['nilai'],
            'time_range' => ['field' => 'dibayar_pada', 'range' => '01/09/2026..31/10/2026'],
            'compare' => 'previous_year',
        ];

        $makassar = $this->byKey($this->analyse($query, 'Asia/Makassar'), 'dibayar_pada');
        $this->assertSame(['2026-10-01'], array_keys($makassar));
        $this->assertSame(['1.00', '2.00'], [$makassar['2026-10-01']['nilai'], $makassar['2026-10-01']['nilai__previous']]);

        $utc = $this->byKey($this->analyse($query, 'UTC'), 'dibayar_pada');
        $this->assertSame(['2026-09-01'], array_keys($utc));
        $this->assertSame(['1.00', '2.00'], [$utc['2026-09-01']['nilai'], $utc['2026-09-01']['nilai__previous']]);
    }

    public function test_totals_compare_per_currency(): void
    {
        $result = $this->analyse(['dimensions' => ['status'], 'measures' => ['nilai'], 'time_range' => ['range' => '@this_month'], 'compare' => 'previous_period', 'totals' => true]);

        $this->assertSame([
            ['currency_code' => 'IDR', 'nilai' => '200.00', 'nilai__previous' => '150.00', 'nilai__change' => '50.00'],
            ['currency_code' => 'USD', 'nilai' => '10.00', 'nilai__previous' => '0', 'nilai__change' => '10.00'],
        ], array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['currency_code', 'nilai', 'nilai__previous', 'nilai__change'])), $result->totals));
        $this->assertEqualsWithDelta(100 / 3, (float) $result->totals[0]['nilai__change_pct'], 0.0001);
        $this->assertNull($result->totals[1]['nilai__change_pct']);
    }

    public function test_a_kpi_without_dimensions_compares_one_row(): void
    {
        $result = $this->analyse(['measures' => ['count'], 'time_range' => ['range' => '@this_month'], 'compare' => 'previous_year']);

        $this->assertSame([['count' => 2, 'count__previous' => 1, 'count__change' => 1, 'count__change_pct' => $result->rows[0]['count__change_pct']]], $result->rows);
        $this->assertEqualsWithDelta(100.0, (float) $result->rows[0]['count__change_pct'], 0.0001);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function analyse(array $input, string $timezone = 'Asia/Makassar'): ResultSet
    {
        $query = app(QueryNormalizer::class)->normalize(app(QueryParser::class)->parse([...['dataset' => self::SALES], ...$input]));
        $dataset = $this->dataset();
        $principal = $this->principal($timezone);
        app(QueryValidator::class)->validate($dataset, $query, $principal);

        return app(TenantRunner::class)->runFor($this->tenantA, static function () use ($dataset, $query, $principal): ResultSet {
            $query = app(FiscalYearRange::class)->resolve($dataset, $query, $principal);
            $compiled = app(QueryCompiler::class)->compile($dataset, $query, $principal);

            return ResultSet::from($dataset, $query, $compiled, app(QueryExecutor::class)->run($compiled, 3000), $principal, 0, app(LabelResolver::class));
        });
    }

    /** @return array<string, array<string, scalar|null>> */
    private function byKey(ResultSet $result, string $key): array
    {
        $rows = [];
        foreach ($result->rows as $row) {
            $rows[(string) $row[$key]] = $row;
        }

        return $rows;
    }

    /** @return array<string, array<string, string|bool>> */
    private function columns(ResultSet $result): array
    {
        $columns = [];
        foreach ($result->toArray()['columns'] as $column) {
            $columns[(string) $column['key']] = $column;
        }

        return $columns;
    }

    private function dataset(): CompiledDataset
    {
        $dataset = app(DatasetRegistry::class)->find(self::SALES);
        $this->assertNotNull($dataset);

        return $dataset;
    }

    private function principal(string $timezone): AnalyticsPrincipal
    {
        $principal = $this->createStub(AnalyticsPrincipal::class);
        $principal->method('tenantId')->willReturn($this->tenantA);
        $principal->method('lockedFilters')->willReturn([]);
        $principal->method('policyScope')->willReturn(['all' => true, 'scope_grants' => []]);
        $principal->method('timezone')->willReturn($timezone);
        $principal->method('now')->willReturnCallback(static fn (): CarbonImmutable => CarbonImmutable::now($timezone));
        $principal->method('rowLimit')->willReturn(5000);
        $principal->method('timeoutMs')->willReturn(3000);

        return $principal;
    }

    private function tenant(string $name): string
    {
        $client = (string) Str::ulid();
        $tenant = (string) Str::ulid();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
        DB::table('clients')->insert(['id' => $client, 'legal_name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenant, 'client_id' => $client, 'name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $tenant;
    }

    private function organization(string $tenantId, string $classification, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'classification' => $classification,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($classification === 'legal_entity') {
            DB::table('legal_entities')->insert([
                'organization_id' => $id, 'company_code' => Str::upper(Str::random(4)), 'country_code' => 'ID',
                'timezone' => 'Asia/Makassar', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function sale(string $tenantId, string $status, string $value, string $currency, string $date, ?string $paidAt = null): void
    {
        DB::table('contoh_a_tr_penjualan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'barang_id' => (string) Str::ulid(),
            'legal_entity_id' => $tenantId === $this->tenantA ? $this->legalEntity : (string) Str::ulid(), 'org_unit_id' => $this->unitA ?? (string) Str::ulid(),
            'status' => $status, 'nilai' => $value, 'currency_code' => $currency, 'tanggal' => $date,
            'dibayar_pada' => $paidAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
