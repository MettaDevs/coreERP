<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\ProviderAccess;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alamat sebuah tenant hanya melayani anggota tenant itu.
 *
 * ## Cacat yang melahirkan berkas ini
 *
 * `ResolveEnvironment` menentukan lingkungan dari alamat, tetapi `CurrentWorkspace` menentukan
 * tenant dari sesi — keanggotaan yang terakhir dipilih, atau yang pertama. Keduanya tidak pernah
 * dicocokkan. Diukur pada 13 September 2026: user yang **hanya** anggota tenant B membuka
 * `tenanta.contoh.co.id/dashboard` dan menerima 200 dengan workspace "Tenant tenantb".
 *
 * Di produksi, yang tinggal di database pusat, itu membingungkan tetapi tidak membocorkan data
 * tenant lain. Di demo dan sandbox, yang punya database sendiri, itu lubang: koneksi sudah digeser
 * ke database milik tenant A, sementara workspace-nya milik B. Query yang lupa menyaring
 * `tenant_id` membaca data A, dan tulisan B mendarat di database A.
 *
 * Harus tertutup sebelum SSO dibangun: di sana "lolos login belum berarti boleh masuk tenant"
 * adalah janji rancangannya, dan janji itu tidak berarti apa pun kalau login sandi hari ini sudah
 * melanggarnya.
 */
class TenantAddressMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->tenantA = $this->tenantWithProduction('tenanta');
        $this->tenantB = $this->tenantWithProduction('tenantb');
    }

    // ------------------------------------------------------------------ jalur merah

    public function test_a_member_of_another_tenant_is_refused_at_this_tenants_address(): void
    {
        $user = $this->memberOf($this->tenantB);

        $this->actingAs($user)
            ->get('http://tenanta.contoh.co.id/dashboard')
            ->assertForbidden();
    }

    public function test_an_account_with_no_membership_at_all_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('http://tenanta.contoh.co.id/dashboard')
            ->assertForbidden();
    }

    /** Revoked bukan anggota. Keanggotaan yang dicabut tidak boleh tetap membuka pintunya. */
    public function test_a_revoked_membership_does_not_open_the_address(): void
    {
        $user = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $user->id,
            'system_role' => 'member',
            'status' => 'revoked',
        ]);

        $this->actingAs($user)
            ->get('http://tenanta.contoh.co.id/dashboard')
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ jalur hijau

    public function test_a_member_is_served_at_their_tenants_address(): void
    {
        $user = $this->memberOf($this->tenantA);

        $this->actingAs($user)
            ->get('http://tenanta.contoh.co.id/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.membership.tenant_id', $this->tenantA->id));
    }

    /**
     * Anggota dua tenant yang terakhir memilih B tetap melihat A di alamat A.
     *
     * Ini wujud kedua cacat yang sama, dan yang lebih sulit terlihat: tidak ada 403, tidak ada
     * galat — hanya tenant yang salah, di alamat yang benar.
     */
    public function test_the_address_wins_over_the_tenant_remembered_in_the_session(): void
    {
        $user = $this->memberOf($this->tenantA);
        $membershipB = TenantMembership::create([
            'tenant_id' => $this->tenantB->id,
            'user_id' => $user->id,
            'system_role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['workspace.membership_id' => $membershipB->id])
            ->get('http://tenanta.contoh.co.id/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.membership.tenant_id', $this->tenantA->id));
    }

    /** Yang ditolak tetap harus dapat keluar — kalau tidak, satu-satunya jalan keluar menghapus cookie. */
    public function test_someone_refused_can_still_log_out(): void
    {
        $user = $this->memberOf($this->tenantB);

        $this->actingAs($user)
            ->post('http://tenanta.contoh.co.id/logout')
            ->assertRedirect();

        $this->assertGuest();
    }

    /** Dan tetap dapat menukarkan undangan ke tenant ini — itu justru cara menjadi anggotanya. */
    public function test_someone_refused_can_still_open_the_invitation_page(): void
    {
        $user = $this->memberOf($this->tenantB);

        $this->actingAs($user)
            ->get('http://tenanta.contoh.co.id/join')
            ->assertOk();
    }

    /**
     * Akun vendor tidak ditolak — akses vendor ke tenant pelanggan diputuskan "bebas dulu".
     *
     * Tetapi ia juga tidak memperoleh workspace tenant lain: vendor yang anggota tenant B masuk ke
     * alamat A **tanpa** workspace, bukan dengan workspace B.
     */
    public function test_a_vendor_account_is_let_in_but_without_another_tenants_workspace(): void
    {
        $vendor = $this->memberOf($this->tenantB);
        ProviderAccess::create(['user_id' => $vendor->id, 'role' => 'provider_admin']);

        $this->actingAs($vendor)
            ->get('http://tenanta.contoh.co.id/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.membership', null));
    }

    // ------------------------------------------------------------------ yang tidak berubah

    /** On-prem, lokal, dan seluruh suite lain: tanpa domain dasar, tidak ada alamat tenant sama sekali. */
    public function test_without_a_base_domain_nothing_changes(): void
    {
        config(['coreerp.base_domain' => null]);
        $user = $this->memberOf($this->tenantB);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.membership.tenant_id', $this->tenantB->id));
    }

    /** Alamat pangkal bukan milik tenant mana pun, jadi tidak menyaring siapa pun. */
    public function test_the_root_address_is_not_a_tenants_address(): void
    {
        $user = $this->memberOf($this->tenantB);

        $this->actingAs($user)
            ->get('http://contoh.co.id/dashboard')
            ->assertOk();
    }

    private function memberOf(Tenant $tenant): User
    {
        $user = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);

        return $user;
    }

    private function tenantWithProduction(string $slug): Tenant
    {
        $client = Client::create(['legal_name' => 'PT '.$slug, 'slug' => 'klien-'.$slug, 'status' => 'active']);
        $tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Tenant '.$slug, 'slug' => $slug, 'status' => 'active']);

        Environment::create([
            'tenant_id' => $tenant->id,
            'kind' => 'production',
            'name' => 'Production',
            'slug' => $slug,
            'database_name' => null,
            'status' => 'active',
            'outbound_allowed' => true,
            'expires_at' => null,
        ]);

        return $tenant;
    }
}
