<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\AnalyticsServiceProvider;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Support\QuerySlots;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\TenantProvisioned;
use App\Platform\Tenant\Actions\RegisterBusiness;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Batas beban engine analitik (area 9, `docs/todo/analitik/kinerja-dan-uji-beban.md` bagian *Batas*):
 *
 * - jatah query bersamaan per tenant lewat kunci bernomor: jatah habis → 429 `analytics.busy` dengan
 *   `Retry-After`, hasil dari cache tetap terlayani, jatah lepas di `finally` walau perhitungan gagal, dan setiap
 *   jatah punya masa berlaku sehingga proses yang mati tidak menguranginya selamanya;
 * - limiter `analytics-interactive` per pengguna, dengan nama sendiri.
 *
 * Setiap penjaga pernah dilihat merah dengan merusak penangkalnya; caranya ditulis di pull request area 9.
 */
class QueryLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const DATASET = 'management-aset.asset-register';

    public function test_a_tenant_without_a_free_slot_is_answered_busy_with_retry_after_but_cached_results_still_flow(): void
    {
        $this->prepareBusinesses();
        $owner = $this->owner('Batas jatah', 'jatah@analitik.test');
        $tenant = (string) $owner->activeMembership()?->tenant_id;
        $query = ['dataset' => self::DATASET, 'measures' => ['count']];

        $this->actingAs($owner)->postJson('/api/v1/analytics/query', $query)->assertOk()->assertJsonPath('meta.cached', false);

        $held = $this->holdAllSlots($tenant);
        // Hasil yang sudah di-cache tidak memakai jatah.
        $this->actingAs($owner)->postJson('/api/v1/analytics/query', $query)->assertOk()->assertJsonPath('meta.cached', true);
        // Query yang harus dihitung ditolak, dengan saran menunggu.
        $this->actingAs($owner)->postJson('/api/v1/analytics/query', [...$query, 'filters' => ['lifecycle_state' => ['received']]])
            ->assertStatus(429)
            ->assertHeader('Retry-After', (string) QuerySlots::RETRY_AFTER_SECONDS)
            ->assertExactJson(['error' => ['code' => 'analytics.busy', 'message' => 'Terlalu banyak perhitungan berjalan bersamaan. Coba lagi sebentar.']]);

        // Satu jatah lepas, query berikutnya dihitung.
        $held[0]->release();
        $this->actingAs($owner)->postJson('/api/v1/analytics/query', [...$query, 'filters' => ['lifecycle_state' => ['received']]])->assertOk();
    }

    public function test_slots_belong_to_one_tenant(): void
    {
        $this->holdAllSlots('tenant-sibuk');

        $this->assertSame('dihitung', app(QuerySlots::class)->run('tenant-lain', 3000, static fn (): string => 'dihitung'));

        try {
            app(QuerySlots::class)->run('tenant-sibuk', 3000, static fn (): string => 'dihitung');
            $this->fail('Tenant tanpa jatah kosong seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame(['analytics.busy', 429, QuerySlots::RETRY_AFTER_SECONDS], [$e->errorCode, $e->status, $e->retryAfter]);
        }
    }

    public function test_the_slot_is_released_even_when_the_computation_fails(): void
    {
        config(['analytics.limits.concurrent_per_tenant' => 1]);

        try {
            app(QuerySlots::class)->run('tenant-gagal', 3000, static fn (): never => throw new RuntimeException('Perhitungan gagal.'));
            $this->fail('Galat perhitungan seharusnya naik ke pemanggil.');
        } catch (RuntimeException $e) {
            $this->assertSame('Perhitungan gagal.', $e->getMessage());
        }

        $this->assertSame('dihitung', app(QuerySlots::class)->run('tenant-gagal', 3000, static fn (): string => 'dihitung'), 'Jatah tidak lepas sesudah perhitungan gagal.');
    }

    public function test_every_slot_has_a_lease_so_a_dead_process_does_not_hold_it_forever(): void
    {
        config(['analytics.limits.concurrent_per_tenant' => 1]);
        $lease = QuerySlots::leaseSeconds(3000);
        $this->assertSame(11, $lease, 'Dua pernyataan masing-masing tiga detik, ditambah lima detik.');

        app(QuerySlots::class)->run('tenant-mati', 3000, function () use ($lease): void {
            // Selama perhitungan masih dalam masa berlaku, jatahnya terpegang.
            $this->assertFalse(Cache::lock(QuerySlots::name('tenant-mati', 0), 60)->get());
            // Proses ini "mati" lama: sesudah masa berlakunya, jatah dapat diambil lagi tanpa dilepas.
            $this->travel($lease + 1)->seconds();
            $this->assertTrue(Cache::lock(QuerySlots::name('tenant-mati', 0), 60)->get(), 'Jatah tidak punya masa berlaku.');
        });
    }

    public function test_the_interactive_limiter_counts_per_user_under_its_own_name(): void
    {
        config(['analytics.rate_limits.interactive_per_minute' => 2]);
        $this->prepareBusinesses();
        $first = $this->owner('Batas laju A', 'laju-a@analitik.test');
        $second = $this->owner('Batas laju B', 'laju-b@analitik.test');
        $query = ['dataset' => self::DATASET, 'measures' => ['count']];

        $this->actingAs($first)->postJson('/api/v1/analytics/query', $query)->assertOk();
        $this->actingAs($first)->postJson('/api/v1/analytics/query', $query)->assertOk();
        $refused = $this->actingAs($first)->postJson('/api/v1/analytics/query', $query)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'analytics.rate_limited')
            ->assertJsonPath('error.message', 'Terlalu banyak permintaan analisis dalam satu menit. Tunggu sebentar, lalu coba lagi.');
        $this->assertNotNull($refused->headers->get('Retry-After'));

        // Pengguna lain punya hitungannya sendiri.
        $this->actingAs($second)->postJson('/api/v1/analytics/query', $query)->assertOk();

        $route = Route::getRoutes()->getByName('api.analytics.query');
        $this->assertNotNull($route);
        $this->assertContains('throttle:'.AnalyticsServiceProvider::INTERACTIVE_LIMITER, $route->gatherMiddleware());

        $blendRoute = Route::getRoutes()->getByName('api.analytics.blend');
        $this->assertNotNull($blendRoute);
        $this->assertContains('throttle:'.AnalyticsServiceProvider::INTERACTIVE_LIMITER, $blendRoute->gatherMiddleware());
    }

    /** @return list<Lock> */
    private function holdAllSlots(string $tenant): array
    {
        $held = [];
        foreach (range(0, config()->integer('analytics.limits.concurrent_per_tenant') - 1) as $slot) {
            $lock = Cache::lock(QuerySlots::name($tenant, $slot), 60);
            $this->assertTrue($lock->get());
            $held[] = $lock;
        }

        return $held;
    }

    /** Module aset dikenal Core, supaya bisnis baru dapat memasangnya, seperti `WalkingSkeletonTest`. */
    private function prepareBusinesses(): void
    {
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->artisan('app:register-manifest', ['module' => 'management-aset'])->assertSuccessful();
        Event::fake([TenantProvisioned::class]);
    }

    /** Owner bisnis baru; peran Owner memegang duty analitik dan baca aset. */
    private function owner(string $business, string $email): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => $business, 'app_ids' => ['management-aset'], 'email' => $email, 'password' => 'password',
        ]);
    }
}
