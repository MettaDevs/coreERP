<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\CoreApp;
use App\Models\Environment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pintu operator: satu-satunya jalur yang melahirkan tenant tanpa pelanggan mendaftar sendiri.
 *
 * Yang dibuktikan di sini dua hal yang tidak dapat dibuktikan salah satunya saja. Pertama, jalur
 * merahnya benar-benar merah — token yang salah, pemasangan tanpa token, email yang sudah punya
 * akun, dan app yang belum tersedia semuanya berhenti sebelum satu baris pun ditulis. Kedua,
 * jalur hijaunya melahirkan tenant yang **lengkap**: baris `tenants` tanpa keanggotaan, role, dan
 * entitlement adalah tenant yang tidak dapat dimasuki siapa pun, dan itu persis yang dihasilkan
 * dua perintah beban uji yang sudah ada di repo ini.
 */
final class PembuatanTenantOlehOperatorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-pusat-admin-uji';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
        config()->set('coreerp.control_plane_token', self::TOKEN);
    }

    public function test_token_yang_salah_ditolak_dan_tidak_meninggalkan_apa_pun(): void
    {
        $response = $this->withToken('token-yang-bukan')->postJson('/api/internal/v1/tenants', $this->muatan());

        $response->assertUnauthorized();
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_permintaan_tanpa_token_ditolak(): void
    {
        $this->postJson('/api/internal/v1/tenants', $this->muatan())->assertUnauthorized();

        $this->assertDatabaseCount('tenants', 0);
    }

    /**
     * Pemasangan yang tidak punya pusat admin menolak semua orang, bukan menerima semua orang.
     *
     * Ini jalur merah yang paling mudah ditulis terbalik: string kosong sama dengan string kosong,
     * jadi penjaga yang hanya membandingkan keduanya akan membuka rute pembuatan tenant di setiap
     * pemasangan on-prem kepada siapa pun yang mengirim header kosong.
     */
    public function test_pemasangan_tanpa_token_menolak_bahkan_token_kosong(): void
    {
        config()->set('coreerp.control_plane_token', null);

        $this->withToken('')->postJson('/api/internal/v1/tenants', $this->muatan())->assertUnauthorized();
        $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->muatan())->assertUnauthorized();

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_email_yang_sudah_terdaftar_ditolak(): void
    {
        User::factory()->create(['email' => 'owner@metta.test']);

        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->muatan([
            'email_admin' => 'owner@metta.test',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('email_admin');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 1);
    }

    /** Huruf besar tidak membuat sebuah email menjadi email lain; kalau ia lolos, akunnya dobel. */
    public function test_email_terdaftar_tetap_ditolak_meski_ditulis_huruf_besar(): void
    {
        User::factory()->create(['email' => 'owner@metta.test']);

        $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->muatan([
            'email_admin' => 'Owner@Metta.test',
        ]))->assertStatus(422)->assertJsonValidationErrors('email_admin');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_app_yang_belum_tersedia_ditolak(): void
    {
        CoreApp::query()->create([
            'id' => 'app-belum-siap',
            'name' => 'App yang belum dijual',
            'version' => '0.1.0',
            'status' => 'planned',
            'database_name' => 'app_belum_siap',
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->muatan([
            'app_ids' => ['app-belum-siap'],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('app_ids.0');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_operator_melahirkan_tenant_yang_lengkap_beserta_sandi_sementaranya(): void
    {
        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->muatan());

        $response->assertCreated()
            ->assertJsonPath('email', 'owner@metta.test')
            ->assertJsonStructure(['tenant_id', 'environment_id', 'email', 'kata_sandi_sementara']);

        $tenantId = Tenant::query()->value('id');
        $this->assertSame($tenantId, $response->json('tenant_id'));
        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'name' => 'PT Metta', 'status' => 'active']);
        $this->assertDatabaseHas('clients', ['legal_name' => 'PT Metta', 'status' => 'active']);

        $pemilik = User::query()->where('email', 'owner@metta.test')->sole();
        $this->assertTrue($pemilik->must_change_password);

        // Sandi yang dipulangkan memang sandi akun itu. Tanpa pemeriksaan ini, endpoint yang
        // mengembalikan string acak yang tidak pernah dipakai apa pun tetap terlihat hijau.
        $sandi = $response->json('kata_sandi_sementara');
        $this->assertIsString($sandi);
        $this->assertTrue(Hash::check($sandi, $pemilik->password));

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenantId,
            'user_id' => $pemilik->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenant_app_entitlements', [
            'tenant_id' => $tenantId,
            'app_id' => 'app-uji',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('roles', ['tenant_id' => $tenantId, 'name' => 'Owner', 'is_active' => true]);
        $this->assertSame(1, DB::table('role_assignments')->count());

        $produksi = Environment::query()->where('tenant_id', $tenantId)->sole();
        $this->assertSame($produksi->id, $response->json('environment_id'));
        $this->assertSame('production', $produksi->kind);
        $this->assertSame('active', $produksi->status);
        $this->assertDatabaseHas('environment_members', [
            'environment_id' => $produksi->id,
            'user_id' => $pemilik->id,
            'status' => 'active',
        ]);
    }

    /**
     * Pintu pendaftaran mandiri tidak ikut berubah.
     *
     * PRD mengunci satu jalur untuk dua pintu, dan bahaya bentuk itu selalu sama: parameter baru
     * yang bocor ke pemanggil lama. Pendaftar mandiri mengetik kata sandinya sendiri, jadi tidak
     * ada yang harus ia ganti saat masuk pertama.
     */
    public function test_pendaftaran_mandiri_tidak_menandai_siapa_pun_wajib_ganti_sandi(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner Sendiri',
            'business_name' => 'PT Sendiri',
            'app_ids' => ['app-uji'],
            'email' => 'sendiri@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->assertFalse(User::query()->where('email', 'sendiri@metta.test')->sole()->must_change_password);
    }

    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function muatan(array $ubah = []): array
    {
        return array_replace([
            'nama_badan_hukum' => 'PT Metta',
            'nama_admin' => 'Owner Metta',
            'email_admin' => 'owner@metta.test',
            'app_ids' => ['app-uji'],
        ], $ubah);
    }
}
