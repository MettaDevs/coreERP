<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Models\Dashboard;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Tests\TestCase;

/**
 * Penyimpanan dasbor dan API layar engine analitik (area 6, `docs/todo/analitik/todo-fase-1.md`): dasbor,
 * widget, dan query tersimpan dengan aturan berbagi preset laporan K-25, versi baris (428 tanpa versi, 409
 * versi basi), pemeriksaan widget saat disimpan, batas widget, tata letak, widget lama yang kuncinya diganti
 * nama atau hilang, katalog dataset, dan halaman Shell-nya.
 *
 * Dihitung sebagai yang melihat dan isolasi tenant dibuktikan test tersendiri
 * (`SharedDashboardRunsAsViewerTest`, `AnalyticsTenantIsolationTest`). Setiap penjaga di sini pernah dilihat
 * merah dengan merusak penangkalnya; caranya ditulis di pull request area 6.
 */
class DashboardApiTest extends TestCase
{
    use BuildsAssetTenants, RefreshDatabase;

    private const RENAMED_DATASET = 'management-aset.asset-register-renamed';

    private User $owner;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();

        $this->owner = $this->business('Tenant dasbor', 'owner@dasbor-analitik.test');
        $this->tenant = (string) $this->membershipOf($this->owner)->tenant_id;
        $legalEntity = $this->organization($this->tenant, 'legal_entity', 'PT Dasbor');
        $unit = $this->organization($this->tenant, 'operating_unit', 'Unit A');
        $this->asset($this->tenant, $legalEntity, $unit, '100000000');
        $this->asset($this->tenant, $legalEntity, $unit, '25000000', 'disposed');
    }

    public function test_dashboard_crud_uses_row_versions_and_archives(): void
    {
        $analyst = $this->analyst();
        $created = $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Aset saya', 'description' => 'Ringkasan'])
            ->assertCreated()
            ->assertHeader('ETag', 'W/"1"')
            ->assertJsonPath('data.name', 'Aset saya')
            ->assertJsonPath('data.shared', false)
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.owner_name', $analyst->name)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.layout', [])
            ->assertJsonPath('data.widgets', []);
        $id = (string) $created->json('data.id');
        $url = "/api/v1/analytics/dashboards/{$id}";

        // Tanpa versi: 428, karena penyimpanan tanpa versi menimpa buta.
        $this->actingAs($analyst)->patchJson($url, ['name' => 'Aset unit'])
            ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
        $updated = $this->actingAs($analyst)->patchJson($url, ['name' => 'Aset unit'], self::ifMatch(1))
            ->assertOk()->assertJsonPath('data.name', 'Aset unit');
        $version = (int) $updated->json('data.version');
        $this->assertGreaterThan(1, $version);
        $updated->assertHeader('ETag', 'W/"'.$version.'"');

        // Versi basi: 409, dan tidak ada yang berubah.
        $this->actingAs($analyst)->patchJson($url, ['name' => 'Ditimpa'], self::ifMatch(1))
            ->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        // Versi juga boleh dibawa sebagai field `version`.
        $version = (int) $this->actingAs($analyst)->patchJson($url, ['description' => null, 'version' => $version])
            ->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.name', 'Aset unit')->json('data.version');

        $widget = $this->actingAs($analyst)->postJson("{$url}/widgets", $this->kpiWidget())->assertCreated()->json('data.id');
        $this->actingAs($analyst)->getJson('/api/v1/analytics/dashboards')->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.widget_count', 1);

        $this->actingAs($analyst)->deleteJson($url)->assertStatus(428);
        $this->actingAs($analyst)->deleteJson($url, [], self::ifMatch($version - 1))->assertStatus(409);
        $this->actingAs($analyst)->deleteJson($url, [], self::ifMatch($version))->assertNoContent();

        // Diarsipkan, bukan dihapus: dasbor dan widget-nya tidak ditemukan lagi, barisnya tetap ada.
        $this->actingAs($analyst)->getJson($url)->assertNotFound();
        $this->actingAs($analyst)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertNotFound();
        $this->assertSoftDeleted('analytics_dashboards', ['id' => $id]);
        $this->assertSoftDeleted('analytics_widgets', ['id' => $widget]);
        // Nama dasbor terarsip boleh dipakai lagi: indeks uniknya parsial.
        $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Aset unit'])->assertCreated();
    }

    public function test_sharing_follows_the_report_preset_rules(): void
    {
        $analyst = $this->analyst();
        $otherAnalyst = $this->analyst();
        $admin = $this->member($this->tenant, ['core.analytics.analyze', 'core.analytics.manage', 'management-aset.aset.manage'], [[null, null]]);
        $viewer = $this->member($this->tenant, ['core.analytics.inquire', 'management-aset.aset.manage'], [[null, null]]);

        $private = $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Pribadi'])->assertCreated()->json('data.id');
        // Membuat dasbor bersama butuh hak mengelola dasbor bersama.
        $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Coba bagikan', 'shared' => true])->assertForbidden();

        // Dasbor pribadi orang lain tidak ada bagi siapa pun, termasuk pengelola dasbor bersama.
        foreach ([$otherAnalyst, $admin] as $stranger) {
            $this->actingAs($stranger)->getJson("/api/v1/analytics/dashboards/{$private}")->assertNotFound();
            $this->actingAs($stranger)->patchJson("/api/v1/analytics/dashboards/{$private}", ['name' => 'Diambil'], self::ifMatch(1))->assertNotFound();
            $this->actingAs($stranger)->deleteJson("/api/v1/analytics/dashboards/{$private}", [], self::ifMatch(1))->assertNotFound();
            $this->actingAs($stranger)->postJson("/api/v1/analytics/dashboards/{$private}/widgets", $this->kpiWidget())->assertNotFound();
            $this->actingAs($stranger)->getJson('/api/v1/analytics/dashboards')->assertOk()->assertJsonMissing(['id' => $private]);
        }

        $shared = $this->actingAs($admin)->postJson('/api/v1/analytics/dashboards', ['name' => 'Bersama', 'shared' => true])
            ->assertCreated()->assertJsonPath('data.shared', true)->json('data.id');

        // Pemilik dasbor pribadi melihat miliknya dan yang bersama; yang bersama tidak boleh ia ubah.
        $this->actingAs($analyst)->getJson('/api/v1/analytics/dashboards')->assertOk()
            ->assertJsonPath('data.0.id', $shared)->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.0.mine', false)
            ->assertJsonPath('data.0.owner_name', $admin->name)
            ->assertJsonPath('data.1.id', $private)->assertJsonPath('data.1.can_edit', true)
            ->assertJsonCount(2, 'data');
        $this->actingAs($analyst)->patchJson("/api/v1/analytics/dashboards/{$shared}", ['name' => 'Diubah'], self::ifMatch(1))->assertForbidden();
        $this->actingAs($analyst)->postJson("/api/v1/analytics/dashboards/{$shared}/widgets", $this->kpiWidget())->assertForbidden();
        $this->actingAs($analyst)->deleteJson("/api/v1/analytics/dashboards/{$shared}", [], self::ifMatch(1))->assertForbidden();

        // Hak melihat saja: membuka dasbor bersama, tidak membuat dasbor.
        $this->actingAs($viewer)->getJson("/api/v1/analytics/dashboards/{$shared}")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->actingAs($viewer)->postJson('/api/v1/analytics/dashboards', ['name' => 'Milik penonton'])->assertForbidden();

        // Pengelola dasbor bersama mengubahnya, siapa pun pembuatnya.
        $this->actingAs($admin)->patchJson("/api/v1/analytics/dashboards/{$shared}", ['name' => 'Bersama unit'], self::ifMatch(1))->assertOk();

        // Menjadikan bersama: pemiliknya, dan hanya bila ia juga pengelola dasbor bersama.
        $this->actingAs($analyst)->patchJson("/api/v1/analytics/dashboards/{$private}", ['shared' => true], self::ifMatch(1))->assertForbidden();
        $own = $this->actingAs($admin)->postJson('/api/v1/analytics/dashboards', ['name' => 'Milik admin'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->patchJson("/api/v1/analytics/dashboards/{$own}", ['shared' => true], self::ifMatch(1))
            ->assertOk()->assertJsonPath('data.shared', true);
        $this->actingAs($otherAnalyst)->getJson("/api/v1/analytics/dashboards/{$own}")->assertOk();

        // Nama pribadi unik per pemilik tanpa membedakan huruf besar; nama bersama unik per tenant.
        $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'PRIBADI'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($otherAnalyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Pribadi'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/analytics/dashboards', ['name' => 'bersama unit', 'shared' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_a_widget_is_validated_against_the_dataset_and_its_type_when_saved(): void
    {
        $analyst = $this->analyst();
        $dashboard = $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Uji widget'])->assertCreated()->json('data.id');
        $store = fn (array $body) => $this->actingAs($analyst)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", ['title' => 'Uji', ...$body]);
        $query = ['dataset' => self::ASSET_DATASET, 'dimensions' => ['lifecycle_state'], 'measures' => ['count']];

        $cases = [
            'kolom tidak dikenal' => [['type' => 'column', 'query' => [...$query, 'dimensions' => ['tidak_ada']], 'visual' => ['x' => 'tidak_ada', 'y' => ['count']]], 422, 'analytics.field_unknown', 'query.dimensions.0'],
            'query tanpa nilai' => [['type' => 'table', 'query' => ['dataset' => self::ASSET_DATASET], 'visual' => ['columns' => ['count']]], 422, 'analytics.invalid_query', 'query.measures'],
            'dataset tidak dikenal' => [['type' => 'kpi', 'query' => ['dataset' => 'management-aset.tidak-ada', 'measures' => ['count']]], 404, 'analytics.dataset_unknown', 'query.dataset'],
            'tile dua nilai' => [['type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count', 'acquisition_value']]], 422, 'analytics.invalid_visual', 'query.measures'],
            'tile berkelompok' => [['type' => 'kpi', 'query' => $query], 422, 'analytics.invalid_visual', 'query.dimensions'],
            'sumbu bukan pengelompok' => [['type' => 'column', 'query' => $query, 'visual' => ['x' => 'currency_code', 'y' => ['count']]], 422, 'analytics.invalid_visual', 'visual.x'],
            'garis tanpa tanggal' => [['type' => 'line', 'query' => $query, 'visual' => ['x' => 'lifecycle_state', 'y' => ['count']]], 422, 'analytics.invalid_visual', 'visual.x'],
            'nilai grafik di luar query' => [['type' => 'bar', 'query' => $query, 'visual' => ['x' => 'lifecycle_state', 'y' => ['acquisition_value']]], 422, 'analytics.invalid_visual', 'visual.y'],
            'pengelompok kedua tanpa seri' => [['type' => 'column', 'query' => [...$query, 'dimensions' => ['lifecycle_state', 'currency_code']], 'visual' => ['x' => 'lifecycle_state', 'y' => ['count']]], 422, 'analytics.invalid_visual', 'visual.series'],
            'bagian tampilan asing' => [['type' => 'table', 'query' => $query, 'visual' => ['columns' => ['count'], 'warna' => 'merah']], 422, 'analytics.invalid_visual', 'visual.warna'],
            'ambang terbalik' => [['type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']], 'visual' => ['thresholds' => ['threshold1' => 20, 'threshold2' => 5]]], 422, 'analytics.invalid_visual', 'visual.thresholds.threshold2'],
            'teks dengan query' => [['type' => 'text', 'query' => $query, 'visual' => ['text' => 'Catatan']], 422, 'analytics.invalid_visual', 'query'],
            'teks kosong' => [['type' => 'text', 'visual' => ['text' => '   ']], 422, 'analytics.invalid_visual', 'visual.text'],
        ];
        foreach ($cases as $case => [$body, $status, $code, $field]) {
            $response = $store($body);
            $this->assertSame($status, $response->status(), $case.': '.$response->getContent());
            $this->assertSame([$code, $field], [$response->json('error.code'), $response->json('error.field')], $case);
        }
        $store(['type' => 'pie', 'query' => $query])->assertUnprocessable()->assertJsonValidationErrors('type');
        $store(['type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']], 'cache_ttl_seconds' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors('cache_ttl_seconds');

        // Penyusun tanpa permission baca dataset tidak dapat menyimpan widget atasnya.
        $withoutAssetRead = $this->member($this->tenant, ['core.analytics.analyze', 'management-aset.pemeliharaan-aset.manage']);
        $ownDashboard = $this->actingAs($withoutAssetRead)->postJson('/api/v1/analytics/dashboards', ['name' => 'Tanpa aset'])->assertCreated()->json('data.id');
        $this->actingAs($withoutAssetRead)->postJson("/api/v1/analytics/dashboards/{$ownDashboard}/widgets", ['title' => 'Uji', ...$this->kpiWidget()])
            ->assertForbidden()->assertJsonPath('error.code', 'analytics.dataset_forbidden')->assertJsonPath('error.field', 'query.dataset');

        // Yang sah disimpan dalam bentuk ringkas, sama dengan badan query layar.
        $widget = $store([
            'type' => 'column',
            'query' => [...$query, 'filters' => ['lifecycle_state' => ['received', 'disposed'], 'currency_code' => ''], 'limit' => null],
            'visual' => ['x' => 'lifecycle_state', 'y' => ['count'], 'stacked' => 'none'],
            'cache_ttl_seconds' => 0,
        ])->assertCreated()->assertHeader('ETag', 'W/"1"')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.cache_ttl_seconds', 0)
            ->assertJsonPath('data.visual', ['x' => 'lifecycle_state', 'y' => ['count'], 'stacked' => 'none']);
        $this->assertSame([
            'dataset' => self::ASSET_DATASET,
            'dimensions' => ['lifecycle_state'],
            'measures' => ['count'],
            'filters' => ['lifecycle_state' => ['disposed', 'received']],
        ], $widget->json('data.query'));
        // `jsonb` menyimpan kunci objek dalam urutannya sendiri; isinya yang sama.
        $this->assertEquals($widget->json('data.query'), Widget::query()->findOrFail($widget->json('data.id'))->query);
        $this->actingAs($analyst)->getJson("/api/v1/analytics/widgets/{$widget->json('data.id')}/data")->assertOk()
            ->assertJsonPath('rows', [
                ['lifecycle_state' => 'disposed', 'lifecycle_state__label' => 'Dilepas', 'count' => 1],
                ['lifecycle_state' => 'received', 'lifecycle_state__label' => 'Diterima', 'count' => 1],
            ]);
        $store(['type' => 'text', 'visual' => ['text' => "Baris satu\nBaris dua"]])->assertCreated()
            ->assertJsonPath('data.dataset_code', null)->assertJsonPath('data.query', null);
    }

    public function test_updating_a_widget_rechecks_its_definition_only_when_it_changes(): void
    {
        $analyst = $this->analyst();
        $dashboard = $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Ubah widget'])->assertCreated()->json('data.id');
        $widget = $this->actingAs($analyst)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", $this->kpiWidget())->assertCreated()->json('data.id');
        $url = "/api/v1/analytics/widgets/{$widget}";

        $this->actingAs($analyst)->patchJson($url, ['title' => 'Jumlah'])->assertStatus(428);
        $version = (int) $this->actingAs($analyst)->patchJson($url, ['title' => 'Jumlah'], self::ifMatch(1))
            ->assertOk()->assertJsonPath('data.title', 'Jumlah')->json('data.version');
        $this->actingAs($analyst)->patchJson($url, ['title' => 'Basi'], self::ifMatch(1))->assertStatus(409);

        // Mengganti jenis memeriksa ulang query tersimpan terhadap jenis barunya.
        $this->actingAs($analyst)->patchJson($url, ['type' => 'donut'], self::ifMatch($version))
            ->assertUnprocessable()->assertJsonPath('error.code', 'analytics.invalid_visual')->assertJsonPath('error.field', 'query.dimensions');
        $version = (int) $this->actingAs($analyst)->patchJson($url, [
            'type' => 'table',
            'query' => ['dataset' => self::ASSET_DATASET, 'dimensions' => ['lifecycle_state'], 'measures' => ['count']],
            'visual' => ['columns' => ['lifecycle_state', 'count'], 'show_totals' => true],
        ], self::ifMatch($version))->assertOk()->assertJsonPath('data.type', 'table')->assertJsonPath('data.visual.show_totals', true)->json('data.version');

        $this->actingAs($analyst)->deleteJson($url)->assertStatus(428);
        $this->actingAs($analyst)->deleteJson($url, [], self::ifMatch($version))->assertNoContent();
        $this->assertSoftDeleted('analytics_widgets', ['id' => $widget]);
        $this->actingAs($analyst)->patchJson($url, ['title' => 'Terarsip'], self::ifMatch($version))->assertNotFound();
    }

    public function test_widget_limit_and_layout_of_a_dashboard(): void
    {
        config(['analytics.limits.widgets_per_dashboard' => 2]);
        $analyst = $this->analyst();
        $dashboard = $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Tata letak'])->assertCreated()->json('data.id');
        $url = "/api/v1/analytics/dashboards/{$dashboard}";
        $first = $this->actingAs($analyst)->postJson("{$url}/widgets", $this->kpiWidget())->assertCreated()->json('data.id');
        $second = $this->actingAs($analyst)->postJson("{$url}/widgets", $this->kpiWidget())->assertCreated()->json('data.id');
        $this->actingAs($analyst)->postJson("{$url}/widgets", $this->kpiWidget())
            ->assertUnprocessable()->assertJsonPath('error.code', 'analytics.limit_exceeded')->assertJsonPath('error.field', 'dashboard');

        // Menambah widget tidak mengubah versi dasbor; widget tanpa letak ditempatkan dua per baris.
        $this->actingAs($analyst)->getJson($url)->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.layout', [
                ['widget_id' => $first, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 2],
                ['widget_id' => $second, 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 2],
            ]);

        $this->actingAs($analyst)->patchJson($url, ['layout' => [['widget_id' => $second, 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 1]]], self::ifMatch(1))
            ->assertOk()
            ->assertJsonPath('data.layout', [
                ['widget_id' => $second, 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 1],
                ['widget_id' => $first, 'x' => 0, 'y' => 1, 'w' => 6, 'h' => 2],
            ]);

        $other = $this->actingAs($analyst)->postJson('/api/v1/analytics/dashboards', ['name' => 'Lain'])->assertCreated()->json('data.id');
        $foreign = $this->actingAs($analyst)->postJson("/api/v1/analytics/dashboards/{$other}/widgets", $this->kpiWidget())->assertCreated()->json('data.id');
        $version = (int) Dashboard::query()->findOrFail($dashboard)->version;
        $invalid = [
            'layout.0.widget_id' => [['widget_id' => $foreign, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 2]],
            'layout.1.widget_id' => [['widget_id' => $first, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 2], ['widget_id' => $first, 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 2]],
            'layout.0.x' => [['widget_id' => $first, 'x' => 8, 'y' => 0, 'w' => 6, 'h' => 2]],
            'layout.0.w' => [['widget_id' => $first, 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 2]],
            'layout.0.h' => [['widget_id' => $first, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4]],
        ];
        foreach ($invalid as $field => $layout) {
            $this->actingAs($analyst)->patchJson($url, ['layout' => $layout], self::ifMatch($version))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_renamed_keys_are_mapped_and_missing_keys_mark_the_widget_instead_of_failing(): void
    {
        app(DatasetRegistry::class)->register(new class implements Dataset
        {
            public function moduleId(): string
            {
                return 'management-aset';
            }

            public function definition(): DatasetDefinition
            {
                return DatasetDefinition::make('management-aset.asset-register-renamed', 'Register aset (uji ganti nama)')
                    ->model(Aset::class)
                    ->permission('management-aset.aset.read')
                    ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
                    ->fieldsFromModel(only: ['lifecycle_state', 'currency_code', 'acquired_on'])
                    ->time('acquired_on', default: true)
                    ->measure('count', 'Jumlah aset', Aggregate::Count)
                    ->version(2, renamed: ['status' => 'lifecycle_state', 'jumlah' => 'count']);
            }
        });
        $dashboard = $this->actingAs($this->owner)->postJson('/api/v1/analytics/dashboards', ['name' => 'Widget lama'])->assertCreated()->json('data.id');

        // Disimpan dengan versi 1 dataset: kunci lamanya `status` dan `jumlah`, dan `group_aset_id` yang kini hilang.
        $stored = fn (string $type, array $query, array $visual): string => Widget::query()->create([
            'tenant_id' => $this->tenant, 'dashboard_id' => $dashboard, 'title' => 'Lama', 'type' => $type,
            'dataset_code' => self::RENAMED_DATASET, 'dataset_version' => 1, 'query' => ['dataset' => self::RENAMED_DATASET, ...$query], 'visual' => $visual,
        ])->id;
        $renamed = $stored('column', ['dimensions' => ['status'], 'measures' => ['jumlah'], 'sort' => [['key' => 'status', 'direction' => 'asc']]], ['x' => 'status', 'y' => ['jumlah']]);
        $removed = $stored('table', ['dimensions' => ['group_aset_id'], 'measures' => ['jumlah']], ['columns' => ['group_aset_id', 'jumlah']]);

        $this->actingAs($this->owner)->getJson("/api/v1/analytics/dashboards/{$dashboard}")->assertOk()
            ->assertJsonPath('data.widgets.0.id', $renamed)
            ->assertJsonPath('data.widgets.0.status', 'ok')
            ->assertJsonPath('data.widgets.0.query.dimensions', ['lifecycle_state'])
            ->assertJsonPath('data.widgets.0.query.measures', ['count'])
            ->assertJsonPath('data.widgets.0.query.sort', [['key' => 'lifecycle_state', 'direction' => 'asc']])
            ->assertJsonPath('data.widgets.0.visual', ['x' => 'lifecycle_state', 'y' => ['count']])
            ->assertJsonPath('data.widgets.1.id', $removed)
            ->assertJsonPath('data.widgets.1.status', 'field_removed')
            ->assertJsonPath('data.widgets.1.missing_fields', ['group_aset_id'])
            ->assertJsonPath('data.widgets.1.visual.columns', ['group_aset_id', 'count']);

        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$renamed}/data")->assertOk()
            ->assertJsonPath('rows.0.lifecycle_state', 'disposed')->assertJsonPath('rows.0.count', 1)
            ->assertJsonPath('rows.1.lifecycle_state', 'received')->assertJsonPath('rows.1.count', 1);
        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$removed}/data")
            ->assertUnprocessable()
            ->assertExactJson(['error' => [
                'code' => 'analytics.field_removed',
                'message' => 'Kolom "group_aset_id" sudah tidak tersedia di data ini. Ubah bagian ini untuk memilih kolom lain.',
                'field' => 'query.dimensions.0',
            ]]);

        // Widget yang rusak tetap dapat diganti nama dan diarsipkan tanpa memeriksa query-nya.
        $this->actingAs($this->owner)->patchJson("/api/v1/analytics/widgets/{$removed}", ['title' => 'Perlu diperbaiki'], self::ifMatch(1))->assertOk()
            ->assertJsonPath('data.status', 'field_removed');

        // Query tersimpan dibaca dengan aturan yang sama.
        $saved = SavedQuery::query()->create([
            'tenant_id' => $this->tenant, 'user_id' => $this->owner->id, 'code' => 'lama', 'name' => 'Lama',
            'dataset_code' => self::RENAMED_DATASET, 'dataset_version' => 1, 'query' => ['dataset' => self::RENAMED_DATASET, 'dimensions' => ['status'], 'measures' => ['jumlah']],
        ]);
        $this->actingAs($this->owner)->getJson("/api/v1/analytics/saved-queries/{$saved->id}")->assertOk()
            ->assertJsonPath('data.status', 'ok')->assertJsonPath('data.query.dimensions', ['lifecycle_state'])->assertJsonPath('data.query.measures', ['count']);
    }

    public function test_saved_queries_follow_the_dashboard_rules(): void
    {
        $analyst = $this->analyst();
        $otherAnalyst = $this->analyst();
        $query = ['dataset' => self::ASSET_DATASET, 'dimensions' => ['lifecycle_state'], 'measures' => ['acquisition_value']];

        $created = $this->actingAs($analyst)->postJson('/api/v1/analytics/saved-queries', ['name' => 'Nilai perolehan per status', 'query' => [...$query, 'filters' => []]])
            ->assertCreated()
            ->assertHeader('ETag', 'W/"1"')
            ->assertJsonPath('data.code', 'nilai-perolehan-per-status')
            ->assertJsonPath('data.dataset_code', self::ASSET_DATASET)
            ->assertJsonPath('data.query', $query)
            ->assertJsonPath('data.shared', false)
            ->assertJsonPath('data.status', 'ok');
        $id = (string) $created->json('data.id');
        $this->actingAs($analyst)->postJson('/api/v1/analytics/saved-queries', ['name' => 'Nilai perolehan per status', 'query' => $query])
            ->assertCreated()->assertJsonPath('data.code', 'nilai-perolehan-per-status-2');
        // Kode huruf kecil, dan unik per tenant — juga terhadap query pribadi pengguna lain.
        $this->actingAs($otherAnalyst)->postJson('/api/v1/analytics/saved-queries', ['name' => 'Lain', 'code' => 'Kode Besar', 'query' => $query])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($otherAnalyst)->postJson('/api/v1/analytics/saved-queries', ['name' => 'Lain', 'code' => 'nilai-perolehan-per-status', 'query' => $query])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($analyst)->postJson('/api/v1/analytics/saved-queries', ['name' => 'Salah', 'query' => [...$query, 'measures' => ['tidak_ada']]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'analytics.field_unknown')->assertJsonPath('error.field', 'query.measures.0');
        $this->actingAs($analyst)->postJson('/api/v1/analytics/saved-queries', ['name' => 'Dibagikan', 'shared' => true, 'query' => $query])->assertForbidden();

        $this->actingAs($otherAnalyst)->getJson("/api/v1/analytics/saved-queries/{$id}")->assertNotFound();
        $this->actingAs($otherAnalyst)->getJson('/api/v1/analytics/saved-queries')->assertOk()->assertJsonMissing(['id' => $id]);
        $this->actingAs($analyst)->getJson('/api/v1/analytics/saved-queries?dataset='.self::ASSET_DATASET)->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($analyst)->getJson('/api/v1/analytics/saved-queries?dataset=management-aset.lain')->assertOk()->assertJsonCount(0, 'data');

        $url = "/api/v1/analytics/saved-queries/{$id}";
        $this->actingAs($analyst)->patchJson($url, ['name' => 'Nilai per status'])->assertStatus(428);
        $version = (int) $this->actingAs($analyst)->patchJson($url, ['name' => 'Nilai per status', 'query' => [...$query, 'measures' => ['count']]], self::ifMatch(1))
            ->assertOk()->assertJsonPath('data.name', 'Nilai per status')->assertJsonPath('data.query.measures', ['count'])
            // Kode tidak ikut berganti: publikasi menunjuk query dengan kode itu.
            ->assertJsonPath('data.code', 'nilai-perolehan-per-status')
            ->json('data.version');
        $this->actingAs($analyst)->patchJson($url, ['name' => 'Basi'], self::ifMatch(1))->assertStatus(409);
        $this->actingAs($analyst)->deleteJson($url, [], self::ifMatch($version))->assertNoContent();
        $this->actingAs($analyst)->getJson($url)->assertNotFound();
        $this->assertSoftDeleted('analytics_saved_queries', ['id' => $id]);
    }

    public function test_the_dataset_catalog_offers_only_what_the_user_may_read(): void
    {
        $codes = fn (User $user): array => array_column($this->actingAs($user)->getJson('/api/v1/analytics/datasets')->assertOk()->json('data'), 'code');
        $this->assertContains(self::ASSET_DATASET, $codes($this->owner));

        $described = $this->actingAs($this->owner)->getJson('/api/v1/analytics/datasets/'.self::ASSET_DATASET)->assertOk()
            ->assertJsonPath('data.code', self::ASSET_DATASET)
            ->assertJsonPath('data.caption', 'Register aset')
            ->assertJsonPath('data.module_id', 'management-aset')
            ->assertJsonPath('data.default_time', 'acquired_on');
        // Dataset module dapat bertambah field waktu (area 5); yang dijaga, field waktu ditandai di kedua tempat.
        $this->assertContains('acquired_on', $described->json('data.times'));
        $measures = collect($described->json('data.measures'))->keyBy('key');
        $this->assertSame(['key' => 'count', 'caption' => 'Jumlah aset', 'aggregate' => 'count', 'format' => 'number'], $measures['count']);
        $this->assertSame(['key' => 'acquisition_value', 'caption' => 'Nilai perolehan', 'aggregate' => 'sum', 'format' => 'money', 'currency_key' => 'currency_code'], $measures['acquisition_value']);
        $fields = collect($described->json('data.fields'))->keyBy('key');
        $this->assertSame(['date', true], [$fields['acquired_on']['type'], $fields['acquired_on']['time']]);
        $this->assertSame(['option', false], [$fields['lifecycle_state']['type'], $fields['lifecycle_state']['time']]);
        $this->assertContains(['value' => 'received', 'label' => 'Diterima'], $fields['lifecycle_state']['options']);

        // Tanpa permission baca aset: tidak ditawarkan, dan isinya ditolak dengan urutan yang sama dengan query.
        $withoutAssetRead = $this->member($this->tenant, ['core.analytics.inquire', 'management-aset.pemeliharaan-aset.manage']);
        $this->assertNotContains(self::ASSET_DATASET, $codes($withoutAssetRead));
        $this->actingAs($withoutAssetRead)->getJson('/api/v1/analytics/datasets/'.self::ASSET_DATASET)
            ->assertForbidden()->assertJsonPath('error.code', 'analytics.dataset_forbidden');
        $this->actingAs($this->owner)->getJson('/api/v1/analytics/datasets/management-aset.tidak-ada')
            ->assertNotFound()->assertJsonPath('error.code', 'analytics.dataset_unknown');

        // Tanpa hak melihat dasbor: gate rute.
        $assetOnly = $this->member($this->tenant, ['management-aset.aset.manage']);
        $this->actingAs($assetOnly)->getJson('/api/v1/analytics/datasets')->assertForbidden();
        $this->actingAs($assetOnly)->getJson('/api/v1/analytics/dashboards')->assertForbidden();
        $this->actingAs($assetOnly)->getJson('/api/v1/analytics/saved-queries')->assertForbidden();
    }

    public function test_shared_dimension_values_follow_the_dataset_data_policy(): void
    {
        $visibleAsset = DB::table('aset_tr_aset')->where('tenant_id', $this->tenant)->first(['legal_entity_id', 'responsible_org_unit_id']);
        $this->assertNotNull($visibleAsset);

        $otherEntity = $this->organization($this->tenant, 'legal_entity', 'PT Di luar jangkauan');
        $otherUnit = $this->organization($this->tenant, 'operating_unit', 'Unit di luar jangkauan');
        $this->asset($this->tenant, $otherEntity, $otherUnit, '5000000');

        $viewer = $this->member(
            $this->tenant,
            ['core.analytics.inquire', 'management-aset.aset.manage'],
            [[(string) $visibleAsset->legal_entity_id, (string) $visibleAsset->responsible_org_unit_id]],
        );

        $this->actingAs($viewer)
            ->getJson('/api/v1/analytics/datasets/'.self::ASSET_DATASET.'/field-values?field=legal_entity_id')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', (string) $visibleAsset->legal_entity_id)
            ->assertJsonPath('data.0.label', 'PT Dasbor')
            ->assertJsonPath('truncated', false);
    }

    public function test_dashboard_pages_render_their_components(): void
    {
        $dashboard = $this->actingAs($this->owner)->postJson('/api/v1/analytics/dashboards', ['name' => 'Beranda'])->assertCreated()->json('data.id');

        // Halaman penuh, bukan jawaban Inertia JSON: `inertia.testing.ensure_pages_exist` memeriksa berkas komponennya
        // (area 7) ada di `resources/js/pages`, jadi nama komponen yang salah ketik atau berkas yang hilang merah di sini.
        $this->actingAs($this->owner)->get('/analytics')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/analytics/index')
                ->where('dashboards.0.id', $dashboard)
                ->where('abilities', ['create' => true, 'share' => true]));
        $this->actingAs($this->owner)->get("/analytics/dashboards/{$dashboard}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/analytics/dashboard')
                ->where('dashboard.id', $dashboard)
                ->where('dashboard.widgets', [])
                ->where('abilities', ['create' => true, 'share' => true]));

        // Pemegang Lihat dasbor saja: daftar terbuka tanpa tombol membuat; dasbor pribadi orang lain tetap 404.
        $viewer = $this->member($this->tenant, ['core.analytics.inquire']);
        $this->actingAs($viewer)->get('/analytics')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/analytics/index')
                ->where('dashboards', [])
                ->where('abilities', ['create' => false, 'share' => false]));
        $this->actingAs($viewer)->get("/analytics/dashboards/{$dashboard}")->assertNotFound();

        // Tanpa hak melihat dasbor: gate rute untuk kedua halaman.
        $assetOnly = $this->member($this->tenant, ['management-aset.aset.manage']);
        $this->actingAs($assetOnly)->get('/analytics')->assertForbidden();
        $this->actingAs($assetOnly)->get("/analytics/dashboards/{$dashboard}")->assertForbidden();
    }

    /** Penyusun dasbor yang boleh membaca seluruh aset tenant ini. */
    private function analyst(): User
    {
        return $this->member($this->tenant, ['core.analytics.analyze', 'management-aset.aset.manage'], [[null, null]]);
    }

    /** @return array<string, mixed> */
    private function kpiWidget(): array
    {
        return ['title' => 'Jumlah aset', 'type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']]];
    }
}
