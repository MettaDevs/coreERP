<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\AnalyticsServiceProvider;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Data widget area 6 di atas cache, log, dan limiter area 9: `GET widgets/{id}/data` memakai
 * `cache_ttl_seconds` widget (kosong = bawaan, `0` = selalu menghitung ulang), `POST widgets/{id}/refresh` —
 * tombol Muat ulang — menghitung ulang tanpa membaca cache lalu menimpanya, keduanya tercatat di log query
 * sebagai jalur `widget`, dan keduanya dibatasi limiter `analytics-interactive`.
 *
 * Dilihat merah dengan mengabaikan `refresh` di `WidgetDataController` (Muat ulang memulangkan angka lama dari
 * cache), dengan tidak meneruskan `cache_ttl_seconds` (widget ber-TTL 0 terbaca dari cache), dan dengan
 * membuang limiter dari rute data widget.
 */
class WidgetDataCacheTest extends TestCase
{
    use BuildsAssetTenants, RefreshDatabase;

    private User $owner;

    private string $tenant;

    private string $legalEntity;

    private string $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();
        $this->owner = $this->business('Tenant data widget', 'owner@widget-cache.test');
        $this->tenant = (string) $this->membershipOf($this->owner)->tenant_id;
        $this->legalEntity = $this->organization($this->tenant, 'legal_entity', 'PT Widget');
        $this->unit = $this->organization($this->tenant, 'operating_unit', 'Unit');
        $this->asset($this->tenant, $this->legalEntity, $this->unit, '1000');
    }

    public function test_widget_data_is_cached_and_reload_recomputes_and_replaces_it(): void
    {
        $widget = $this->widget(null);

        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()
            ->assertJsonPath('rows', [['count' => 1]])->assertJsonPath('meta.cached', false);
        $this->asset($this->tenant, $this->legalEntity, $this->unit, '2000');

        // Dalam masa TTL bawaan, data widget dibaca dari cache.
        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()
            ->assertJsonPath('rows', [['count' => 1]])->assertJsonPath('meta.cached', true);
        // Muat ulang menghitung ulang, lalu hasilnya yang dibaca berikutnya.
        $this->actingAs($this->owner)->postJson("/api/v1/analytics/widgets/{$widget}/refresh")->assertOk()
            ->assertJsonPath('rows', [['count' => 2]])->assertJsonPath('meta.cached', false);
        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()
            ->assertJsonPath('rows', [['count' => 2]])->assertJsonPath('meta.cached', true);

        $this->assertSame(['widget'], DB::table('analytics_query_log')->where('tenant_id', $this->tenant)->distinct()->pluck('source')->all());
    }

    public function test_a_widget_with_ttl_zero_is_always_recomputed(): void
    {
        $widget = $this->widget(0);

        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()->assertJsonPath('meta.cached', false);
        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()->assertJsonPath('meta.cached', false);
        $this->assertSame(0, DB::table('analytics_query_cache')->where('tenant_id', $this->tenant)->count());
    }

    public function test_widget_data_routes_use_the_interactive_limiter(): void
    {
        foreach (['api.analytics.widgets.data', 'api.analytics.widgets.refresh'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Rute {$name} tidak ada.");
            $this->assertContains('throttle:'.AnalyticsServiceProvider::INTERACTIVE_LIMITER, $route->gatherMiddleware(), "Rute {$name} tanpa limiter analitik.");
        }

        config(['analytics.rate_limits.interactive_per_minute' => 1]);
        $widget = $this->widget(null);
        $this->actingAs($this->owner)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/analytics/widgets/{$widget}/refresh")
            ->assertStatus(429)->assertJsonPath('error.code', 'analytics.rate_limited');
    }

    private function widget(?int $ttl): string
    {
        $dashboard = $this->actingAs($this->owner)->postJson('/api/v1/analytics/dashboards', ['name' => 'Dasbor cache '.($ttl ?? 'bawaan')])->assertCreated()->json('data.id');

        return (string) $this->actingAs($this->owner)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", [
            'title' => 'Jumlah aset', 'type' => 'kpi', 'cache_ttl_seconds' => $ttl,
            'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
        ])->assertCreated()->json('data.id');
    }
}
