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
final class OperatorTenantProvisioningTest extends TestCase
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

    public function test_a_wrong_token_is_rejected_and_leaves_nothing_behind(): void
    {
        $response = $this->withToken('token-yang-bukan')->postJson('/api/internal/v1/tenants', $this->payload());

        $response->assertUnauthorized();
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $this->postJson('/api/internal/v1/tenants', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('tenants', 0);
    }

    /**
     * Pemasangan yang tidak punya pusat admin menolak semua orang, bukan menerima semua orang.
     *
     * Ini jalur merah yang paling mudah ditulis terbalik: string kosong sama dengan string kosong,
     * jadi penjaga yang hanya membandingkan keduanya akan membuka rute pembuatan tenant di setiap
     * pemasangan on-prem kepada siapa pun yang mengirim header kosong.
     */
    public function test_an_installation_without_a_token_refuses_even_an_empty_token(): void
    {
        config()->set('coreerp.control_plane_token', null);

        $this->withToken('')->postJson('/api/internal/v1/tenants', $this->payload())->assertUnauthorized();
        $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_an_email_that_is_already_registered_is_rejected(): void
    {
        User::factory()->create(['email' => 'owner@metta.test']);

        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->payload([
            'admin_email' => 'owner@metta.test',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('admin_email');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 1);
    }

    /** Huruf besar tidak membuat sebuah email menjadi email lain; kalau ia lolos, akunnya dobel. */
    public function test_a_registered_email_is_still_rejected_even_written_in_capitals(): void
    {
        User::factory()->create(['email' => 'owner@metta.test']);

        $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->payload([
            'admin_email' => 'Owner@Metta.test',
        ]))->assertStatus(422)->assertJsonValidationErrors('admin_email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_app_that_is_not_available_yet_is_rejected(): void
    {
        CoreApp::query()->create([
            'id' => 'app-belum-siap',
            'name' => 'App yang belum dijual',
            'version' => '0.1.0',
            'status' => 'planned',
            'database_name' => 'app_belum_siap',
        ]);

        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->payload([
            'app_ids' => ['app-belum-siap'],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('app_ids.0');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_operator_gives_birth_to_a_complete_tenant_together_with_its_temporary_password(): void
    {
        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('email', 'owner@metta.test')
            ->assertJsonStructure(['tenant_id', 'environment_id', 'email', 'temporary_password']);

        $tenantId = Tenant::query()->value('id');
        $this->assertSame($tenantId, $response->json('tenant_id'));
        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'name' => 'PT Metta', 'status' => 'active']);
        $this->assertDatabaseHas('clients', ['legal_name' => 'PT Metta', 'status' => 'active']);

        $owner = User::query()->where('email', 'owner@metta.test')->sole();
        $this->assertTrue($owner->must_change_password);

        // Sandi yang dipulangkan memang sandi akun itu. Tanpa pemeriksaan ini, endpoint yang
        // mengembalikan string acak yang tidak pernah dipakai apa pun tetap terlihat hijau.
        $password = $response->json('temporary_password');
        $this->assertIsString($password);
        $this->assertTrue(Hash::check($password, $owner->password));

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenantId,
            'user_id' => $owner->id,
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

        $production = Environment::query()->where('tenant_id', $tenantId)->sole();
        $this->assertSame($production->id, $response->json('environment_id'));
        $this->assertSame('production', $production->kind);
        $this->assertSame('active', $production->status);
        $this->assertDatabaseHas('environment_members', [
            'environment_id' => $production->id,
            'user_id' => $owner->id,
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
    public function test_self_registration_marks_nobody_as_required_to_change_their_password(): void
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
     * @param  array<string, mixed>  $changed
     * @return array<string, mixed>
     */
    private function payload(array $changed = []): array
    {
        return array_replace([
            'legal_name' => 'PT Metta',
            'admin_name' => 'Owner Metta',
            'admin_email' => 'owner@metta.test',
            'app_ids' => ['app-uji'],
        ], $changed);
    }
}
