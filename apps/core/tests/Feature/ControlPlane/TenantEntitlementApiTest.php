<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\CoreApp;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hak app tenant yang dibaca pusat admin saat menerbitkan lisensi situs.
 *
 * Yang dijaga di sini penting karena salahnya tidak terlihat di Core sama sekali: daftar yang terlalu
 * panjang menjadi lisensi yang membuka app yang tidak dibayar di server klien selama 30 hari, dan
 * daftar yang terlalu pendek menutup app yang dibayar. Karena itu setiap syarat "sedang berhak" —
 * status, sudah mulai, belum berakhir — punya baris uji yang hanya melanggar syarat itu saja.
 */
final class TenantEntitlementApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-pusat-admin-uji';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        config()->set('coreerp.control_plane_token', self::TOKEN);

        $this->tenant = $this->makeTenant('uji-hak');

        foreach (['app-aktif', 'app-berakhir-nanti', 'app-belum-mulai', 'app-sudah-berakhir', 'app-ditangguhkan', 'app-tenant-lain', 'app-baru-mulai'] as $appId) {
            CoreApp::query()->create([
                'id' => $appId,
                'name' => $appId,
                'version' => '0.1.0',
                'status' => 'available',
                'database_name' => 'core_erp',
            ]);
        }
    }

    // ------------------------------------------------------------------ penjaga

    public function test_the_entitlements_are_not_readable_without_the_control_plane_token(): void
    {
        $this->getJson($this->url($this->tenant->id))->assertUnauthorized();
        $this->withToken('token-salah')->getJson($this->url($this->tenant->id))->assertUnauthorized();
    }

    /** Pemasangan tanpa pusat admin — on-prem, termasuk server klien yang dikunci lisensi — menolak semua orang. */
    public function test_an_installation_without_a_token_refuses_even_an_empty_one(): void
    {
        config()->set('coreerp.control_plane_token', null);

        $this->withToken('')->getJson($this->url($this->tenant->id))->assertUnauthorized();
        $this->withToken(self::TOKEN)->getJson($this->url($this->tenant->id))->assertUnauthorized();
    }

    /** Kredensial app module bukan kunci pintu ini: pintunya milik pusat admin. */
    public function test_app_service_headers_do_not_open_it(): void
    {
        $this->withHeaders([
            'X-CoreERP-App-Id' => 'app-aktif',
            'X-CoreERP-Service-Token' => 'apa-saja',
            'X-CoreERP-Tenant-Id' => $this->tenant->id,
        ])->getJson($this->url($this->tenant->id))->assertUnauthorized();
    }

    public function test_an_unknown_tenant_is_404(): void
    {
        $this->withToken(self::TOKEN)
            ->getJson($this->url((string) Str::ulid()))
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        // Bukan ULID sama sekali — tetap 404, bukan 500 dari database.
        $this->withToken(self::TOKEN)->getJson($this->url('bukan-tenant'))->assertNotFound();
    }

    // ------------------------------------------------------------------ jendela aktif

    public function test_only_entitlements_that_are_active_started_and_not_ended_are_listed_sorted(): void
    {
        $this->entitle($this->tenant, 'app-aktif', 'active', now()->subMonth(), null);
        $this->entitle($this->tenant, 'app-berakhir-nanti', 'active', now()->subMonth(), now()->addSecond());
        // Batas bawah termasuk: hak yang mulai tepat sekarang sudah berlaku.
        $this->entitle($this->tenant, 'app-baru-mulai', 'active', now(), null);

        $this->entitle($this->tenant, 'app-belum-mulai', 'active', now()->addSecond(), null);
        // Batas atas tidak termasuk: hak yang berakhir tepat sekarang sudah tidak berlaku.
        $this->entitle($this->tenant, 'app-sudah-berakhir', 'active', now()->subMonth(), now());
        $this->entitle($this->tenant, 'app-ditangguhkan', 'suspended', now()->subMonth(), null);

        $other = $this->makeTenant('tenant-lain');
        $this->entitle($other, 'app-tenant-lain', 'active', now()->subMonth(), null);

        $this->withToken(self::TOKEN)
            ->getJson($this->url($this->tenant->id))
            ->assertOk()
            ->assertExactJson([
                'tenant_id' => $this->tenant->id,
                'apps' => ['app-aktif', 'app-baru-mulai', 'app-berakhir-nanti'],
            ]);
    }

    /** Tenant yang hanya memakai Core tetap 200 dengan daftar kosong, bukan 404. */
    public function test_a_tenant_without_entitlements_gets_an_empty_list(): void
    {
        $this->withToken(self::TOKEN)
            ->getJson($this->url($this->tenant->id))
            ->assertOk()
            ->assertExactJson(['tenant_id' => $this->tenant->id, 'apps' => []]);
    }

    // ------------------------------------------------------------------ pembantu

    private function url(string $tenantId): string
    {
        return '/api/internal/v1/tenants/'.$tenantId.'/entitlements';
    }

    private function makeTenant(string $slug): Tenant
    {
        $client = Client::create(['legal_name' => 'PT '.$slug, 'slug' => $slug, 'status' => 'active']);

        return Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT '.$slug,
            'slug' => str_replace('-', '', $slug),
            'status' => 'active',
        ]);
    }

    private function entitle(Tenant $tenant, string $appId, string $status, \DateTimeInterface $startsAt, ?\DateTimeInterface $endsAt): void
    {
        DB::table('tenant_app_entitlements')->insert([
            'tenant_id' => $tenant->id,
            'app_id' => $appId,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
