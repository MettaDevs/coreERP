<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Foundation\FiscalCalendar\Actions\FiscalCalendarService;
use App\Foundation\FiscalCalendar\Models\FiscalCalendar;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Platform\Analytics\Support\TestPrincipal;
use Tests\TestCase;

/**
 * Token tahun fiskal (area 13.6) dan rumus di widget (13.3) lewat jalur sungguhan: `POST api/v1/analytics/query`
 * dengan sesi workspace, `RunQuery` dengan principal tanpa workspace, dan widget yang disimpan lalu dihitung.
 *
 * Dua perusahaan dengan tahun fiskal berbeda — Juli–Juni dan Januari–Desember — di satu tenant. "Sekarang"
 * 20 Oktober 2026. Token tahun fiskal hanya menentukan rentang tanggal; perusahaan mana yang datanya dihitung
 * tetap urusan saringan dan kebijakan data.
 */
class FiscalYearAndStoredFormulaTest extends TestCase
{
    use BuildsAssetTenants, RefreshDatabase;

    private User $owner;

    private string $tenant;

    private string $july;

    private string $january;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Jakarta'));

        $this->owner = $this->business('Tenant fiskal', 'owner@fiskal.test');
        $this->tenant = (string) $this->membershipOf($this->owner)->tenant_id;
        $this->july = $this->organization($this->tenant, 'legal_entity', 'PT Fiskal Juli');
        $this->january = $this->organization($this->tenant, 'legal_entity', 'PT Fiskal Januari');
        $this->calendar($this->july, 7);
        $this->calendar($this->january, 1);
        $unit = $this->organization($this->tenant, 'operating_unit', 'Unit fiskal');

        $this->assetOn($this->july, $unit, '1000', '2026-09-01');
        $this->assetOn($this->july, $unit, '2000', '2026-03-01');
        $this->assetOn($this->january, $unit, '4000', '2026-09-01');
        $this->assetOn($this->january, $unit, '8000', '2025-12-01');
    }

    public function test_the_fiscal_year_comes_from_the_workspace_company_and_each_company_has_its_own_cache_entry(): void
    {
        $query = ['dataset' => self::ASSET_DATASET, 'measures' => ['count', 'acquisition_value'], 'time_range' => ['range' => '@this_fiscal_year']];

        // Juli 2026–Juni 2027: dua aset September 2026.
        $this->actingAs($this->owner)->withSession(['workspace.legal_entity_id' => $this->july])->postJson('/api/v1/analytics/query', $query)->assertOk()
            ->assertJsonPath('rows', [['currency_code' => 'IDR', 'count' => 2, 'acquisition_value' => '5000.00']])
            ->assertJsonPath('meta.cached', false);
        // Query yang sama dari workspace perusahaan lain: tahun fiskal Januari–Desember 2026, dihitung sendiri,
        // tidak dibaca dari cache perusahaan pertama.
        $this->actingAs($this->owner)->withSession(['workspace.legal_entity_id' => $this->january])->postJson('/api/v1/analytics/query', $query)->assertOk()
            ->assertJsonPath('rows', [['currency_code' => 'IDR', 'count' => 3, 'acquisition_value' => '7000.00']])
            ->assertJsonPath('meta.cached', false);
        // Perusahaan pertama lagi: dari cache-nya sendiri.
        $this->actingAs($this->owner)->withSession(['workspace.legal_entity_id' => $this->july])->postJson('/api/v1/analytics/query', $query)->assertOk()
            ->assertJsonPath('rows.0.count', 2)->assertJsonPath('meta.cached', true);

        // Tahun fiskal lalu: Juli 2025–Juni 2026.
        $this->actingAs($this->owner)->withSession(['workspace.legal_entity_id' => $this->july])
            ->postJson('/api/v1/analytics/query', [...$query, 'time_range' => ['range' => '@last_fiscal_year']])->assertOk()
            ->assertJsonPath('rows.0.count', 2)->assertJsonPath('rows.0.acquisition_value', '10000.00');
    }

    public function test_a_single_company_filter_wins_over_the_workspace(): void
    {
        $this->actingAs($this->owner)->withSession(['workspace.legal_entity_id' => $this->july])->postJson('/api/v1/analytics/query', [
            'dataset' => self::ASSET_DATASET, 'measures' => ['count'],
            'filters' => ['legal_entity_id' => [$this->january]],
            'time_range' => ['range' => '@this_fiscal_year'],
            'compare' => 'previous_period',
        ])->assertOk()
            // Tahun fiskal 2026 perusahaan Januari, dibandingkan dengan tahun fiskal 2025-nya.
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.count', 1)->assertJsonPath('rows.0.count__previous', 1)->assertJsonPath('rows.0.count__change', 0)
            ->assertJsonPath('rows.0.count__change_pct', fn (string $value): bool => (float) $value === 0.0);
    }

    public function test_a_fiscal_token_without_exactly_one_known_company_is_refused_with_a_reason(): void
    {
        $other = $this->organization((string) $this->membershipOf($this->business('Tenant lain', 'lain@fiskal.test'))->tenant_id, 'legal_entity', 'PT Tenant Lain');
        $this->calendar($other, 1);
        $bare = $this->organization($this->tenant, 'legal_entity', 'PT Tanpa Kalender');

        foreach ([
            [[$this->july, $this->january], 'filters.legal_entity_id', 'tepat satu perusahaan'],
            [[$bare], 'time_range.range', 'belum mencakup hari ini'],
            [[$other], 'time_range.range', 'tidak dikenal'],
        ] as [$companies, $field, $message]) {
            $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [
                'dataset' => self::ASSET_DATASET, 'measures' => ['count'],
                'filters' => ['legal_entity_id' => $companies], 'time_range' => ['range' => '@this_fiscal_year'],
            ])->assertStatus(422)->assertJsonPath('error.code', 'analytics.invalid_query')->assertJsonPath('error.field', $field)
                ->assertJsonPath('error.message', fn (string $text): bool => str_contains($text, $message));
        }

        // Principal tanpa workspace (publikasi, job) dan tanpa saringan perusahaan: ditolak, bukan tahun kalender.
        try {
            app(RunQuery::class)->handle(new TestPrincipal($this->tenant), (new QueryNormalizer)->normalize((new QueryParser)->parse([
                'dataset' => self::ASSET_DATASET, 'measures' => ['count'], 'time_range' => ['range' => '@this_fiscal_year'],
            ])));
            $this->fail('Tahun fiskal tanpa perusahaan seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('time_range.range', $e->field);
            $this->assertStringContainsString('butuh satu perusahaan', $e->getMessage());
        }
    }

    public function test_a_widget_stores_its_formula_and_comparison_and_computes_them_as_the_viewer(): void
    {
        $dashboard = $this->actingAs($this->owner)->postJson('/api/v1/analytics/dashboards', ['name' => 'Dasbor rumus'])->assertCreated()->json('data.id');
        $query = [
            'dataset' => self::ASSET_DATASET,
            'measures' => ['rata'],
            'formulas' => [['key' => 'rata', 'caption' => 'Rata-rata perolehan', 'expression' => 'BAGI([acquisition_value]; [count])', 'format' => 'money']],
            'time_range' => ['range' => '@this_year'],
            'compare' => 'previous_year',
        ];

        $widget = $this->actingAs($this->owner)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", ['title' => 'Rata-rata', 'type' => 'kpi', 'query' => $query])
            ->assertCreated()
            ->assertJsonPath('data.query.formulas.0.expression', 'BAGI([acquisition_value]; [count])')
            ->assertJsonPath('data.query.formulas.0.format', 'money')
            ->assertJsonPath('data.query.compare', 'previous_year')
            ->assertJsonPath('data.visual.measure', 'rata')
            ->assertJsonPath('data.status', 'ok')
            ->json('data.id');

        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()
            ->assertJsonPath('columns.1', ['key' => 'rata', 'kind' => 'measure', 'caption' => 'Rata-rata perolehan', 'type' => 'number', 'format' => 'money', 'currency_key' => 'currency_code'])
            ->assertJsonPath('columns.2.key', 'rata__previous')
            ->assertJsonPath('rows.0.currency_code', 'IDR')
            // 2026: 7.000 untuk tiga aset; 2025: satu aset 8.000.
            ->assertJsonPath('rows.0.rata', fn (string $value): bool => abs((float) $value - 7000 / 3) < 0.0001)
            ->assertJsonPath('rows.0.rata__previous', fn (string $value): bool => (float) $value === 8000.0);

        // Rumus yang rusak ditolak saat disimpan, di posisinya, dengan path di bawah `query.`.
        $this->actingAs($this->owner)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", [
            'title' => 'Rusak', 'type' => 'kpi',
            'query' => [...$query, 'formulas' => [['key' => 'rata', 'expression' => 'BAGI([acquisition_value]; [count]']]],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'analytics.invalid_formula')
            ->assertJsonPath('error.field', 'query.formulas.0.expression')
            ->assertJsonPath('error.position', 5);
    }

    private function calendar(string $legalEntity, int $startMonth): void
    {
        $tenant = (string) DB::table('organizations')->where('id', $legalEntity)->value('tenant_id');
        $calendar = FiscalCalendar::query()->create(['tenant_id' => $tenant, 'code' => 'CAL-'.Str::upper(Str::random(5)), 'name' => 'Kalender uji']);
        $fiscal = app(FiscalCalendarService::class);
        foreach ([2025, 2026] as $year) {
            $start = CarbonImmutable::create($year, $startMonth, 1);
            $fiscal->defineYear($calendar, 'FY'.$year, $start, $start->addYear()->subDay(), $fiscal->monthlyPeriods($start));
        }
        DB::table('legal_entities')->where('organization_id', $legalEntity)->update(['fiscal_calendar_id' => $calendar->id]);
    }

    private function assetOn(string $legalEntity, string $unit, string $value, string $acquiredOn): void
    {
        $this->asset($this->tenant, $legalEntity, $unit, $value);
        DB::table('aset_tr_aset')->where('tenant_id', $this->tenant)->where('acquisition_value', $value)->update(['acquired_on' => $acquiredOn]);
    }
}
