<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Slicer, cross-filter, drill, dan antrean ekspor area 12. */
class AnalyticsArea12Test extends TestCase
{
    use BuildsAssetTenants, RefreshDatabase;

    private User $owner;

    private string $tenant;

    private string $legalEntity;

    private string $firstUnit;

    private string $secondUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();
        $this->owner = $this->business('Tenant area 12', 'owner@area12.test');
        $this->tenant = (string) $this->membershipOf($this->owner)->tenant_id;
        $this->legalEntity = $this->organization($this->tenant, 'legal_entity', 'Entitas uji');
        $this->firstUnit = $this->organization($this->tenant, 'operating_unit', 'Unit A');
        $this->secondUnit = $this->organization($this->tenant, 'operating_unit', 'Unit B');
    }

    public function test_slicer_defaults_url_values_and_clearing_are_applied_to_widget_data(): void
    {
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '100000', 'received');
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '200000', 'disposed');
        [$dashboard, $widget] = $this->tableWidget();
        $slicers = [[
            'key' => 'status',
            'title' => 'Status aset',
            'source' => ['type' => 'field', 'dataset' => self::ASSET_DATASET, 'field' => 'lifecycle_state'],
            'control' => 'multi_select',
            'default_value' => ['disposed'],
        ]];
        $this->actingAs($this->owner)->patchJson("/api/v1/analytics/dashboards/{$dashboard}", ['slicers' => $slicers], self::ifMatch(1))
            ->assertOk()
            ->assertJsonPath('data.slicers.0.key', 'status');

        $path = "/api/v1/analytics/widgets/{$widget}/data";
        $this->actingAs($this->owner)->getJson($path)->assertOk()
            ->assertJsonPath('rows.0.lifecycle_state', 'disposed')
            ->assertJsonPath('rows.0.count', 1);
        $this->actingAs($this->owner)->getJson($path.'?'.http_build_query(['s' => ['status' => ['received']]]))->assertOk()
            ->assertJsonPath('rows.0.lifecycle_state', 'received')
            ->assertJsonPath('rows.0.count', 1)
            ->assertJsonPath('meta.cached', false);
        $this->actingAs($this->owner)->getJson($path.'?s%5Bstatus%5D=')->assertOk()
            ->assertJsonCount(2, 'rows');
        $this->actingAs($this->owner)->getJson($path.'?s%5Basing%5D=received')->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.invalid_query');
    }

    public function test_cross_filter_narrows_widget_data_and_unknown_fields_are_rejected(): void
    {
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '100000', 'received');
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '200000', 'disposed');
        [, $widget] = $this->tableWidget();
        $path = "/api/v1/analytics/widgets/{$widget}/data";

        $this->actingAs($this->owner)->getJson($path.'?c%5Blifecycle_state%5D=received')->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.lifecycle_state', 'received')
            ->assertJsonPath('rows.0.count', 1);
        $this->actingAs($this->owner)->getJson($path.'?c%5Bnot_a_field%5D=received')->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.field_unknown');
    }

    public function test_drill_rows_match_the_asset_list_for_the_same_user_and_scope(): void
    {
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '100000', 'received');
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '200000', 'disposed');
        $this->asset($this->tenant, $this->legalEntity, $this->secondUnit, '300000', 'received');
        [$dashboard, $widget] = $this->tableWidget(shared: true, type: 'kpi');
        $viewer = $this->member($this->tenant, ['core.analytics.inquire', 'management-aset.aset.manage'], [[$this->legalEntity, $this->firstUnit]]);

        $list = $this->actingAs($viewer)->getJson('/api/modules/management-aset/v1/aset')->assertOk()->json('data');
        $drill = $this->actingAs($viewer)->postJson('/api/v1/analytics/drill', ['widget_id' => $widget, 'values' => []])
            ->assertOk()->json('rows');

        $this->assertEqualsCanonicalizing(array_column($list, 'id'), array_column($drill, 'id'));
        $this->assertCount(2, $drill);
        $this->assertSame($dashboard, (string) $this->actingAs($viewer)->getJson("/api/v1/analytics/dashboards/{$dashboard}")->json('data.id'));
    }

    public function test_time_drill_down_keeps_the_clicked_month_and_returns_the_next_granularity(): void
    {
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '100000', 'received');
        $dashboard = $this->actingAs($this->owner)->postJson('/api/v1/analytics/dashboards', ['name' => 'Perincian waktu'])->assertCreated()->json('data.id');
        $query = [
            'dataset' => self::ASSET_DATASET,
            'dimensions' => [['field' => 'acquired_on', 'granularity' => 'month']],
            'measures' => ['count'],
        ];
        $widget = $this->actingAs($this->owner)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", [
            'title' => 'Aset per bulan',
            'type' => 'line',
            'query' => $query,
            'visual' => ['x' => 'acquired_on', 'y' => ['count']],
        ])->assertCreated()->json('data.id');
        $point = $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()->json('rows.0');

        $response = $this->actingAs($this->owner)->postJson('/api/v1/analytics/drill', [
            'widget_id' => $widget,
            'action' => 'down',
            'dimension_field' => 'acquired_on',
            'values' => [['field' => 'acquired_on', 'value' => $point['acquired_on'], 'granularity' => 'month']],
        ])->assertOk();

        $response->assertJsonPath('next.granularity', 'day')
            ->assertJsonPath('result.rows.0.acquired_on', '2026-09-01')
            ->assertJsonPath('result.rows.0.count', 1);
    }

    public function test_widget_and_drill_exports_use_the_core_export_queue(): void
    {
        config(['queue.default' => 'sync', 'reporting.disk' => 'area12-test']);
        Storage::fake('area12-test');
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '100000', 'received');
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '200000', 'disposed');
        [, $widget] = $this->tableWidget();

        $widgetExport = $this->actingAs($this->owner)->postJson('/api/v1/analytics/exports', [
            'widget_id' => $widget,
            'kind' => 'widget',
        ])->assertStatus(202)->assertJsonPath('data.kind', 'analytics')->json('data');
        $widgetRow = DB::table('report_exports')->where('id', $widgetExport['id'])->first();
        $this->assertSame('done', $widgetRow->status, (string) $widgetRow->failure_message);
        $this->assertSame(2, (int) $widgetRow->row_count);
        Storage::disk('area12-test')->assertExists($widgetRow->file_path);
        $visible = $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()->json('rows');
        $widgetRows = IOFactory::load(Storage::disk('area12-test')->path($widgetRow->file_path))->getActiveSheet()->toArray(null, true, false);
        $this->assertSame(
            array_map(static fn (array $row): array => [$row['lifecycle_state'], $row['count']], $visible),
            array_slice($widgetRows, 1),
        );

        $visibleDrill = $this->actingAs($this->owner)->postJson('/api/v1/analytics/drill', [
            'widget_id' => $widget,
            'values' => [['field' => 'lifecycle_state', 'value' => 'received']],
        ])->assertOk()->json('rows');
        $drillExport = $this->actingAs($this->owner)->postJson('/api/v1/analytics/exports', [
            'widget_id' => $widget,
            'kind' => 'drill',
            'values' => [['field' => 'lifecycle_state', 'value' => 'received']],
        ])->assertStatus(202)->assertJsonPath('data.kind', 'analytics')->json('data');
        $drillRow = DB::table('report_exports')->where('id', $drillExport['id'])->first();
        $this->assertSame('done', $drillRow->status, (string) $drillRow->failure_message);
        $this->assertSame(1, (int) $drillRow->row_count);
        Storage::disk('area12-test')->assertExists($drillRow->file_path);
        $drillRows = IOFactory::load(Storage::disk('area12-test')->path($drillRow->file_path))->getActiveSheet()->toArray(null, true, false);
        $this->assertSame(
            array_map(static fn (array $row): array => [$row['lifecycle_state']], $visibleDrill),
            array_slice($drillRows, 1),
        );
    }

    public function test_drill_pages_use_a_keyset_cursor_and_limit_each_page_to_one_hundred_rows(): void
    {
        $this->asset($this->tenant, $this->legalEntity, $this->firstUnit, '100000', 'received');
        $template = get_object_vars(DB::table('aset_tr_aset')->where('tenant_id', $this->tenant)->firstOrFail());
        for ($index = 1; $index < 102; $index++) {
            DB::table('aset_tr_aset')->insert([
                ...$template,
                'id' => (string) Str::ulid(),
                'creation_key' => 'area12-'.$index,
                'kode' => 'AREA12-'.$index,
                'nama' => 'Aset '.$index,
                'created_at' => now()->addSeconds($index),
                'updated_at' => now()->addSeconds($index),
            ]);
        }
        [, $widget] = $this->tableWidget(type: 'kpi');
        $first = $this->actingAs($this->owner)->postJson('/api/v1/analytics/drill', ['widget_id' => $widget, 'values' => []])
            ->assertOk()->assertJsonCount(100, 'rows')->assertJsonStructure(['next_cursor'])->json();
        $this->assertNotNull($first['next_cursor']);
        $second = $this->actingAs($this->owner)->postJson('/api/v1/analytics/drill', [
            'widget_id' => $widget,
            'values' => [],
            'cursor' => $first['next_cursor'],
        ])->assertOk()->assertJsonCount(2, 'rows')->json();
        $this->assertNull($second['next_cursor']);
    }

    /** @return array{string, string} */
    private function tableWidget(bool $shared = false, string $type = 'table'): array
    {
        $dashboard = $this->actingAs($this->owner)->postJson('/api/v1/analytics/dashboards', [
            'name' => 'Dasbor '.$type.' '.Str::random(5),
            'shared' => $shared,
        ])->assertCreated()->json('data.id');
        $query = ['dataset' => self::ASSET_DATASET, 'dimensions' => ['lifecycle_state'], 'measures' => ['count']];
        $visual = $type === 'kpi'
            ? []
            : ['columns' => ['lifecycle_state', 'count']];
        if ($type === 'kpi') {
            $query = ['dataset' => self::ASSET_DATASET, 'measures' => ['count']];
        }
        $widget = $this->actingAs($this->owner)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", [
            'title' => 'Aset',
            'type' => $type,
            'query' => $query,
            'visual' => $visual,
        ])->assertCreated()->json('data.id');

        return [(string) $dashboard, (string) $widget];
    }
}
