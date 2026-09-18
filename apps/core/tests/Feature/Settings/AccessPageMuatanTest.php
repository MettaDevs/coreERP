<?php

namespace Tests\Feature\Settings;

use App\Models\Client;
use App\Models\CoreApp;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Penjaga muatan halaman `/settings/access`, sesuai docs/dev/33-muatan-halaman-dan-paginasi.md.
 *
 * Yang dijaga di sini bukan tampilan melainkan **berapa banyak yang dibawa satu kunjungan**.
 * Ketiganya gagal pada bentuk halaman sebelum 18 September 2026, dan gagalnya dengan cara yang
 * tidak pernah terlihat di layar: halaman tetap benar, hanya membawa 117 KB dan dua query per role.
 */
class AccessPageMuatanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Tenant} */
    private function tenantDenganAkses(): array
    {
        $slug = 'tenant-'.strtolower(Str::random(6));

        $client = Client::create([
            'legal_name' => 'PT Uji Muatan',
            'slug' => $slug,
            'status' => 'active',
        ]);

        $tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Muatan',
            'slug' => $slug,
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);

        Organization::create([
            'tenant_id' => $tenant->id,
            'classification' => 'legal_entity',
            'name' => 'PT Uji Muatan',
            'status' => 'active',
        ]);

        return [$user, $tenant];
    }

    /**
     * Satu app beserta pohon izin utuhnya, dan hak tenant atasnya.
     *
     * Dibuat sampai ke permission dengan sengaja: pohon inilah yang dulu berangkat utuh pada setiap
     * kunjungan, dan yang sekarang harus **tidak** ikut pada respons pertama.
     */
    private function appDenganPohonIzin(Tenant $tenant, string $appId): void
    {
        CoreApp::create([
            'id' => $appId,
            'name' => 'App '.$appId,
            'version' => '0.1.0',
            'status' => 'available',
            'database_name' => null,
            'has_ui' => true,
            'description' => 'Bahan uji muatan halaman',
        ]);

        DB::table('tenant_app_entitlements')->insert([
            'tenant_id' => $tenant->id,
            'app_id' => $appId,
            'status' => 'active',
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('app_entry_points')->insert([
            'code' => $appId.'.barang',
            'app_id' => $appId,
            'name' => 'Barang',
            'type' => 'page',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insert([
            'code' => $appId.'.barang.read',
            'app_id' => $appId,
            'entry_point_code' => $appId.'.barang',
            'access_level' => 'read',
            'name' => 'Lihat barang',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_privileges')->insert([
            'code' => $appId.'.barang.maintain',
            'app_id' => $appId,
            'name' => 'Pelihara barang',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_privilege_permissions')->insert([
            'privilege_code' => $appId.'.barang.maintain',
            'permission_code' => $appId.'.barang.read',
        ]);

        DB::table('security_duties')->insert([
            'code' => $appId.'.barang.manage',
            'app_id' => $appId,
            'name' => 'Kelola barang',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_duty_privileges')->insert([
            'duty_code' => $appId.'.barang.manage',
            'privilege_code' => $appId.'.barang.maintain',
        ]);
    }

    public function test_katalog_izin_tidak_ikut_pada_respons_pertama(): void
    {
        [$user, $tenant] = $this->tenantDenganAkses();
        $this->appDenganPohonIzin($tenant, 'app-uji');

        $this->actingAs($user)
            ->get('/settings/access?section=members')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/access')
                ->has('members')
                // Prop `defer` memang tidak dikirim pada permintaan pertama; itulah gunanya.
                ->missing('apps')
                ->missing('hierarchies')
                ->etc(),
            );
    }

    public function test_katalog_izin_tiba_pada_permintaan_susulan_dengan_kolom_seperlunya(): void
    {
        [$user, $tenant] = $this->tenantDenganAkses();
        $this->appDenganPohonIzin($tenant, 'app-uji');

        // Versi aset diambil dari Inertia sendiri, bukan dikarang. Permintaan parsial dengan versi
        // yang tidak cocok dijawab 409, dan pesan gagalnya tidak menyebut versi sama sekali.
        // Caranya memancing 409 itu: kirim versi yang pasti salah, lalu baca versi benar dari
        // header jawabannya — mekanisme yang sama dipakai peramban.
        $versi = (string) $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'versi-yang-pasti-salah'])
            ->get('/settings/access?section=members')
            ->assertStatus(409)
            ->headers->get('X-Inertia-Version');

        // Diperiksa sebagai JSON, bukan lewat `assertInertia`: pembantu itu membaca view `page`
        // milik respons HTML, dan permintaan parsial tidak pernah merendernya.
        $respons = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $versi,
                'X-Inertia-Partial-Component' => 'settings/access',
                'X-Inertia-Partial-Data' => 'apps',
            ])
            ->get('/settings/access?section=members')
            ->assertOk();

        /** @var array<int, array<string, mixed>> $apps */
        $apps = $respons->json('props.apps');

        $this->assertCount(1, $apps);
        $this->assertSame(['id', 'name', 'duties'], array_keys($apps[0]));
        $this->assertSame('app-uji', $apps[0]['id']);

        $duty = $apps[0]['duties'][0];
        $this->assertSame(['code', 'app_id', 'name', 'privileges'], array_keys($duty));
        $this->assertSame('app-uji.barang.manage', $duty['code']);

        $privilege = $duty['privileges'][0];
        $this->assertSame(['code', 'name', 'permissions'], array_keys($privilege));
        $this->assertSame('app-uji.barang.maintain', $privilege['code']);

        // Inti aturan 3: yang sampai ke peramban hanya kolom yang dibaca layar. `created_at`,
        // `updated_at`, `tenant_id`, `source`, `status`, `published_at`, dan `description`
        // pernah ikut semuanya — 109 KB untuk dua module — dan tidak satu pun dibaca.
        $permission = $privilege['permissions'][0];
        $this->assertSame(['code', 'name', 'access_level'], array_keys($permission));
        $this->assertSame('app-uji.barang.read', $permission['code']);
        $this->assertSame('read', $permission['access_level']);
    }

    /**
     * Jumlah query halaman ini tidak boleh bergantung pada jumlah role.
     *
     * Yang dibandingkan selisihnya, bukan angka mutlak: yang dijaga bentuknya — datar terhadap
     * jumlah baris — bukan sebuah nilai yang berubah setiap kali satu prop ditambahkan.
     */
    public function test_jumlah_query_tidak_tumbuh_mengikuti_jumlah_role(): void
    {
        [$user, $tenant] = $this->tenantDenganAkses();
        $this->appDenganPohonIzin($tenant, 'app-uji');

        $jumlah = 0;
        DB::listen(function () use (&$jumlah): void {
            $jumlah++;
        });

        $hitung = function () use ($user, &$jumlah): int {
            $jumlah = 0;
            $this->actingAs($user)->get('/settings/access?section=members')->assertOk();

            return $jumlah;
        };

        $this->buatRole($tenant, 1);
        $denganSatuRole = $hitung();

        $this->buatRole($tenant, 11, mulai: 1);
        $denganDuaBelasRole = $hitung();

        $this->assertSame(
            $denganSatuRole,
            $denganDuaBelasRole,
            'Jumlah query berubah dari '.$denganSatuRole.' menjadi '.$denganDuaBelasRole
            .' ketika role bertambah dari 1 menjadi 12. Ada query di dalam perulangan role; '
            .'lihat docs/dev/33-muatan-halaman-dan-paginasi.md aturan 4.'
        );
    }

    private function buatRole(Tenant $tenant, int $banyak, int $mulai = 0): void
    {
        for ($i = $mulai; $i < $mulai + $banyak; $i++) {
            $role = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Role '.$i,
                'is_active' => true,
            ]);

            // Duty dipasang supaya penelusuran izin per role benar-benar punya sesuatu untuk
            // ditelusuri; role kosong tidak akan membuktikan apa pun tentang jumlah querynya.
            DB::table('security_role_duties')->insert([
                'role_id' => $role->id,
                'duty_code' => 'app-uji.barang.manage',
            ]);
        }
    }
}
