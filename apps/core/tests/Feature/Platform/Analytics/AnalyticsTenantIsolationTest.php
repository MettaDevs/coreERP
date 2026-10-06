<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Models\Dashboard;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Actions\RegisterBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Isolasi tenant untuk setiap endpoint analitik (`docs/todo/analitik/keamanan.md`, *Test penjaga keamanan*):
 * dua tenant, keduanya dengan module aset dan pemilik yang memegang setiap duty, nol kebocoran.
 *
 * - Setiap rute yang menerima id dasbor, widget, query tersimpan, atau publikasi menjawab 404 kepada tenant
 *   lain — termasuk dasbor **bersama**, yang di dalam tenant-nya terlihat oleh semua — dan barisnya tidak
 *   berubah. Daftar rutenya dibaca dari router, jadi rute baru yang belum ada di tabel test ini menggagalkannya.
 * - Daftar, katalog dataset, data widget, dan daftar publikasi hanya memuat milik tenant pemanggil.
 *
 * Dilihat merah dengan membuang saringan tenant di `BindsWithinActiveTenant` (tenant B membuka, mengubah, dan
 * mengarsipkan dasbor bersama tenant A) dan dengan membuang saringan tenant di `DashboardAccess::visible()`
 * (daftar tenant B memuat dasbor dan query bersama tenant A).
 */
class AnalyticsTenantIsolationTest extends TestCase
{
    use BuildsAssetTenants, PublishesAnalytics, RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private Dashboard $dashboardA;

    private Widget $widgetA;

    private SavedQuery $savedA;

    private Publication $publicationA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();

        $this->ownerA = $this->business('Tenant isolasi A', 'owner-a@isolasi-analitik.test');
        $this->ownerB = $this->business('Tenant isolasi B', 'owner-b@isolasi-analitik.test');
        foreach ([[$this->ownerA, 3], [$this->ownerB, 1]] as [$owner, $assets]) {
            $tenant = (string) $this->membershipOf($owner)->tenant_id;
            $legalEntity = $this->organization($tenant, 'legal_entity', 'PT Isolasi');
            $unit = $this->organization($tenant, 'operating_unit', 'Unit');
            for ($i = 0; $i < $assets; $i++) {
                $this->asset($tenant, $legalEntity, $unit, '1000');
            }
        }

        $dashboard = $this->actingAs($this->ownerA)->postJson('/api/v1/analytics/dashboards', ['name' => 'Bersama A', 'shared' => true])->assertCreated()->json('data.id');
        $widget = $this->actingAs($this->ownerA)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", [
            'title' => 'Jumlah aset', 'type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
        ])->assertCreated()->json('data.id');
        $saved = $this->actingAs($this->ownerA)->postJson('/api/v1/analytics/saved-queries', [
            'name' => 'Jumlah aset A', 'shared' => true, 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
        ])->assertCreated()->json('data.id');

        $this->dashboardA = Dashboard::query()->whereKey($dashboard)->firstOrFail();
        $this->widgetA = Widget::query()->whereKey($widget)->firstOrFail();
        $this->savedA = SavedQuery::query()->whereKey($saved)->firstOrFail();

        // Area 15: publikasi tenant A dari query tersimpannya.
        $client = $this->integrationClient($this->ownerA, 'Klien A');
        $publication = $this->publish($this->ownerA, ['name' => 'Publikasi A', 'saved_query_id' => $saved, 'client_ids' => [$client['id']]])->assertCreated()->json('data.id');
        $this->publicationA = Publication::query()->whereKey($publication)->firstOrFail();
    }

    public function test_every_endpoint_with_an_id_answers_404_to_another_tenant(): void
    {
        $d = $this->dashboardA->id;
        $w = $this->widgetA->id;
        $s = $this->savedA->id;
        $p = $this->publicationA->id;
        $version = self::ifMatch(1);
        $widgetBody = ['title' => 'Susupan', 'type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']]];

        /** @var array<string, array{0: string, 1: string, 2: array<string, mixed>}> $calls nama rute => [metode, uri, badan] */
        $calls = [
            'analytics.dashboards.show' => ['GET', "/analytics/dashboards/{$d}", []],
            'api.analytics.dashboards.show' => ['GET', "/api/v1/analytics/dashboards/{$d}", []],
            'api.analytics.dashboards.update' => ['PATCH', "/api/v1/analytics/dashboards/{$d}", ['name' => 'Diambil alih']],
            'api.analytics.dashboards.destroy' => ['DELETE', "/api/v1/analytics/dashboards/{$d}", []],
            'api.analytics.widgets.store' => ['POST', "/api/v1/analytics/dashboards/{$d}/widgets", $widgetBody],
            'api.analytics.widgets.update' => ['PATCH', "/api/v1/analytics/widgets/{$w}", ['title' => 'Diambil alih']],
            'api.analytics.widgets.destroy' => ['DELETE', "/api/v1/analytics/widgets/{$w}", []],
            'api.analytics.widgets.data' => ['GET', "/api/v1/analytics/widgets/{$w}/data", []],
            'api.analytics.widgets.refresh' => ['POST', "/api/v1/analytics/widgets/{$w}/refresh", []],
            'api.analytics.saved-queries.show' => ['GET', "/api/v1/analytics/saved-queries/{$s}", []],
            'api.analytics.saved-queries.update' => ['PATCH', "/api/v1/analytics/saved-queries/{$s}", ['name' => 'Diambil alih']],
            'api.analytics.saved-queries.destroy' => ['DELETE', "/api/v1/analytics/saved-queries/{$s}", []],
            'api.analytics.publications.update' => ['PATCH', "/api/v1/analytics/publications/{$p}", ['name' => 'Diambil alih']],
            'api.analytics.publications.preview' => ['GET', "/api/v1/analytics/publications/{$p}/preview", []],
            'api.analytics.publications.pause' => ['POST', "/api/v1/analytics/publications/{$p}/pause", ['version' => 1]],
            'api.analytics.publications.resume' => ['POST', "/api/v1/analytics/publications/{$p}/resume", ['version' => 1]],
            'api.analytics.publications.revoke' => ['POST', "/api/v1/analytics/publications/{$p}/revoke", ['version' => 1]],
            'api.analytics.publications.take-over' => ['POST', "/api/v1/analytics/publications/{$p}/take-over", ['version' => 1]],
        ];

        // Setiap rute analitik yang menerima id ada di tabel di atas; rute baru tanpa baris di sini gagal.
        $withId = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'api.analytics.') || str_starts_with((string) $route->getName(), 'analytics.'))
            ->filter(fn (RoutingRoute $route): bool => array_intersect($route->parameterNames(), ['dashboard', 'widget', 'savedQuery', 'publication']) !== [])
            ->map(fn (RoutingRoute $route): string => (string) $route->getName())
            ->sort()->values()->all();
        $this->assertSame(collect(array_keys($calls))->sort()->values()->all(), $withId);

        // Semua jawaban dikumpulkan dulu, supaya kegagalan menunjukkan setiap rute yang bocor sekaligus.
        $statuses = [];
        foreach ($calls as $name => [$method, $uri, $body]) {
            $headers = in_array($method, ['PATCH', 'DELETE'], true) ? $version : [];
            $statuses[$name] = $this->actingAs($this->ownerB)->json($method, $uri, $body, $headers)->status();
        }
        $this->assertSame(array_fill_keys(array_keys($calls), 404), $statuses, 'Rute analitik menjawab selain 404 kepada tenant lain.');

        // Tidak ada yang berubah di tenant A, dan pemiliknya masih membuka semuanya.
        foreach ([$this->dashboardA, $this->widgetA, $this->savedA, $this->publicationA] as $row) {
            $fresh = $row->fresh();
            $this->assertNotNull($fresh, $row::class.' tenant A terarsip oleh tenant B.');
            $this->assertSame(1, $fresh->version, $row::class.' tenant A diubah oleh tenant B.');
        }
        $this->assertSame(1, $this->dashboardA->widgets()->count());
        $this->actingAs($this->ownerA)->getJson("/api/v1/analytics/dashboards/{$d}")->assertOk()->assertJsonPath('data.name', 'Bersama A');
        $this->actingAs($this->ownerA)->getJson("/api/v1/analytics/widgets/{$w}/data")->assertOk()->assertJsonPath('rows', [['count' => 3]]);
    }

    public function test_lists_catalog_and_widget_data_show_only_the_callers_tenant(): void
    {
        $this->actingAs($this->ownerB)->getJson('/api/v1/analytics/dashboards')->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($this->ownerB)->getJson('/api/v1/analytics/saved-queries')->assertOk()->assertExactJson(['data' => []]);
        $this->withoutVite();
        $this->actingAs($this->ownerB)->get('/analytics/publications')->assertOk()
            ->assertInertia(fn ($page) => $page->where('publications', [])->where('savedQueries', []));
        $this->actingAs($this->ownerA)->getJson('/api/v1/analytics/dashboards')->assertOk()->assertJsonPath('data.0.id', $this->dashboardA->id);

        // Nama yang sama boleh di tenant lain: keunikan dasbor bersama per tenant.
        $dashboardB = $this->actingAs($this->ownerB)->postJson('/api/v1/analytics/dashboards', ['name' => 'Bersama A', 'shared' => true])->assertCreated()->json('data.id');
        $widgetB = $this->actingAs($this->ownerB)->postJson("/api/v1/analytics/dashboards/{$dashboardB}/widgets", [
            'title' => 'Jumlah aset', 'type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->ownerB)->getJson("/api/v1/analytics/widgets/{$widgetB}/data")->assertOk()->assertJsonPath('rows', [['count' => 1]]);
        // Kode query tersimpan juga unik per tenant, bukan seluruh server.
        $this->actingAs($this->ownerB)->postJson('/api/v1/analytics/saved-queries', [
            'name' => 'Jumlah aset A', 'code' => $this->savedA->code, 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
        ])->assertCreated();

        // Katalog mengikuti module yang terpasang untuk tenant pemanggil.
        $this->assertContains(self::ASSET_DATASET, array_column($this->actingAs($this->ownerB)->getJson('/api/v1/analytics/datasets')->assertOk()->json('data'), 'code'));
        $withoutAssets = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant tanpa aset', 'app_ids' => [], 'email' => 'owner-c@isolasi-analitik.test', 'password' => 'password',
        ]);
        $this->actingAs($withoutAssets)->getJson('/api/v1/analytics/datasets')->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($withoutAssets)->getJson('/api/v1/analytics/datasets/'.self::ASSET_DATASET)
            ->assertNotFound()->assertJsonPath('error.code', 'analytics.dataset_unknown');
    }
}
