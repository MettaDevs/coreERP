<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Environment;
use App\Models\IntegrationClient;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\Integration\PushDestination;
use Database\Seeders\AppCatalogSeeder;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Klien integrasi dan autentikasinya (area 4, K-03).
 */
class IntegrationClientTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        [$this->owner, $this->membership] = $this->tenantBaru('owner@metta.test', 'PT Metta');
        $this->unit($this->membership->tenant_id, 'Klinik A', 'KLN-A');
        Http::preventStrayRequests();
        // Mode on-prem kecuali test yang sengaja menguji SaaS: `.env` pengembang boleh menyetel
        // domain dasar, dan hasil test tidak boleh bergantung pada berkas itu.
        config(['coreerp.base_domain' => null]);
    }

    public function test_klien_pull_menerima_token_sekali_dan_hanya_digest_yang_disimpan(): void
    {
        $jawaban = $this->buat(['name' => 'Old-finance'])->assertCreated();
        $token = (string) $jawaban->json('token');
        [$id, $rahasia] = explode('.', $token, 2);

        $this->assertSame($id, $jawaban->json('data.id'));
        $this->assertNull($jawaban->json('signing_secret'));
        $this->assertDatabaseHas('integration_clients', ['id' => $id, 'token_digest' => hash('sha256', $rahasia)]);
        $this->assertSame(0, DB::table('integration_clients')->where('token_digest', $rahasia)->count());

        $this->unitsDengan($token)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'KLN-A');
        $this->assertNotNull(IntegrationClient::query()->find($id)?->last_used_at);
    }

    public function test_token_salah_atau_dicabut_ditolak(): void
    {
        $token = (string) $this->buat()->json('token');
        [$id] = explode('.', $token, 2);

        $this->unitsDengan($id.'.salah')->assertUnauthorized();
        $this->unitsDengan('bukan-token')->assertUnauthorized();
        $this->withHeaders(['Accept' => 'application/json'])->getJson('/api/internal/v1/operating-units')->assertUnauthorized();

        $this->actingAs($this->owner)->postJson("/api/v1/integration-clients/{$id}/revoke")->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->unitsDengan($token)->assertUnauthorized();
    }

    public function test_alamat_di_luar_allowlist_ditolak(): void
    {
        $token = (string) $this->buat(['allowed_ips' => ['203.0.113.0/28']])->json('token');

        $this->unitsDengan($token)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->unitsDengan($token)->assertOk();
    }

    public function test_cakupan_yang_kurang_menghasilkan_403(): void
    {
        $token = (string) $this->buat(['scopes' => ['vendors.read']])->json('token');

        $this->unitsDengan($token)->assertForbidden();
    }

    public function test_salinan_sandbox_menjawab_503_dengan_alasannya(): void
    {
        $token = (string) $this->buat()->json('token');
        $sandbox = Environment::create([
            'tenant_id' => $this->membership->tenant_id, 'kind' => 'sandbox', 'name' => 'Uji sandbox',
            'slug' => 'uji-sandbox', 'database_name' => null, 'hosting' => Environment::HOSTING_PROVIDER,
            'status' => 'active', 'outbound_allowed' => false,
        ]);
        $this->app->instance(ActiveEnvironment::KEY, $sandbox->id);

        $this->unitsDengan($token)->assertStatus(503)
            ->assertJsonPath('message', fn (string $pesan): bool => str_contains($pesan, 'sandbox'));
    }

    public function test_tenant_tidak_dapat_ditimpa_lewat_header(): void
    {
        $token = (string) $this->buat()->json('token');
        [, $lain] = $this->tenantBaru('owner@lain.test', 'PT Lain');
        $this->unit($lain->tenant_id, 'Klinik Tenant Lain', 'LAIN-A');

        $jawaban = $this->withHeaders(['X-CoreERP-Tenant-Id' => $lain->tenant_id])->unitsDengan($token)->assertOk();

        $this->assertSame(['KLN-A'], array_column($jawaban->json('data'), 'number'));
    }

    public function test_menerbitkan_ulang_token_mematikan_token_lama(): void
    {
        $lama = (string) $this->buat()->json('token');
        [$id] = explode('.', $lama, 2);

        $baru = (string) $this->actingAs($this->owner)
            ->postJson("/api/v1/integration-clients/{$id}/rotate-token")->assertOk()->json('token');

        $this->assertNotSame($lama, $baru);
        $this->unitsDengan($lama)->assertUnauthorized();
        $this->unitsDengan($baru)->assertOk();
    }

    public function test_klien_push_wajib_https_dan_rahasia_penanda_tangan_disimpan_terenkripsi(): void
    {
        $this->buat(['delivery_mode' => 'push', 'push_url' => 'http://finance.example.test/hook'])
            ->assertStatus(422)->assertJsonValidationErrors('push_url');
        $this->buat(['delivery_mode' => 'push', 'push_url' => null])
            ->assertStatus(422)->assertJsonValidationErrors('push_url');

        $jawaban = $this->buat(['delivery_mode' => 'push', 'push_url' => 'https://finance.example.test/hook'])->assertCreated();
        $rahasia = (string) $jawaban->json('signing_secret');

        $this->assertSame(48, strlen($rahasia));
        $mentah = (string) DB::table('integration_clients')->where('id', $jawaban->json('data.id'))->value('signing_secret');
        $this->assertNotSame($rahasia, $mentah, 'Rahasia disimpan terenkripsi.');
        $this->assertSame($rahasia, IntegrationClient::query()->findOrFail($jawaban->json('data.id'))->signing_secret);
        $jawaban->assertJsonMissingPath('data.signing_secret');
    }

    public function test_kirim_uji_ditandatangani_hmac_atas_stempel_dan_badan(): void
    {
        $dikirim = [];
        Http::fake(function (HttpRequest $permintaan) use (&$dikirim) {
            $dikirim[] = $permintaan;

            return Http::response(['diterima' => true], 200);
        });
        $jawaban = $this->buat(['delivery_mode' => 'push', 'push_url' => 'https://finance.example.test/hook']);
        $rahasia = (string) $jawaban->json('signing_secret');
        $id = (string) $jawaban->json('data.id');

        $this->actingAs($this->owner)->postJson("/api/v1/integration-clients/{$id}/test-push")
            ->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.status', 200);

        $this->assertCount(1, $dikirim);
        $permintaan = $dikirim[0];
        $stempel = $permintaan->header('X-CoreERP-Event-Timestamp')[0];
        $this->assertSame(
            hash_hmac('sha256', $stempel.'.'.$permintaan->body(), $rahasia),
            $permintaan->header('X-CoreERP-Event-Signature')[0],
        );
        $this->assertSame($id, $permintaan->header('X-CoreERP-Client-Id')[0]);
        $this->assertSame('coreerp.integration.test', json_decode($permintaan->body(), true)['type']);
    }

    public function test_kirim_uji_di_sandbox_tidak_mengirim_apa_pun(): void
    {
        $dikirim = [];
        Http::fake(function (HttpRequest $permintaan) use (&$dikirim) {
            $dikirim[] = $permintaan->url();

            return Http::response([], 200);
        });
        $id = (string) $this->buat(['delivery_mode' => 'push', 'push_url' => 'https://finance.example.test/hook'])->json('data.id');
        $sandbox = Environment::create([
            'tenant_id' => $this->membership->tenant_id, 'kind' => 'sandbox', 'name' => 'Uji sandbox',
            'slug' => 'uji-sandbox', 'database_name' => null, 'hosting' => Environment::HOSTING_PROVIDER,
            'status' => 'active', 'outbound_allowed' => false,
        ]);
        $this->app->instance(ActiveEnvironment::KEY, $sandbox->id);

        $this->actingAs($this->owner)->postJson("/api/v1/integration-clients/{$id}/test-push")
            ->assertOk()->assertJsonPath('data.ok', false);

        $this->assertSame([], $dikirim);
    }

    public function test_test_push_to_an_unknown_host_reports_the_cause_without_curl_noise(): void
    {
        Http::fake(function (HttpRequest $request) {
            throw new ConnectException(
                'cURL error 6: Could not resolve host: finance.example.invalid (see https://curl.se/libcurl/c/libcurl-errors.html) for https://finance.example.invalid/hook',
                $request->toPsrRequest(),
                null,
                ['errno' => 6, 'error' => 'Could not resolve host: finance.example.invalid'],
            );
        });
        $id = (string) $this->buat(['delivery_mode' => 'push', 'push_url' => 'https://finance.example.invalid/hook'])->json('data.id');

        $this->actingAs($this->owner)->postJson("/api/v1/integration-clients/{$id}/test-push")
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.status', null)
            ->assertJsonPath('data.message', 'Tujuan tidak dapat dijangkau: Could not resolve host: finance.example.invalid');
    }

    public function test_di_saas_url_push_ke_jaringan_privat_ditolak_tetapi_di_on_prem_boleh(): void
    {
        $alamat = ['10.0.0.5'];
        $this->app->instance(PushDestination::class, new PushDestination(function () use (&$alamat): array {
            return $alamat;
        }));

        config(['coreerp.base_domain' => 'erp.example.test']);
        $this->buat(['name' => 'Privat', 'delivery_mode' => 'push', 'push_url' => 'https://finance.internal/hook'])
            ->assertStatus(422)->assertJsonValidationErrors('push_url');
        $this->buat(['name' => 'Metadata', 'delivery_mode' => 'push', 'push_url' => 'https://169.254.169.254/latest'])
            ->assertStatus(422)->assertJsonValidationErrors('push_url');

        $alamat = ['203.0.113.20'];
        $this->buat(['name' => 'Publik', 'delivery_mode' => 'push', 'push_url' => 'https://finance.example.test/hook'])
            ->assertCreated();

        config(['coreerp.base_domain' => null]);
        $alamat = ['10.0.0.5'];
        $this->buat(['name' => 'LAN', 'delivery_mode' => 'push', 'push_url' => 'https://finance.internal/hook'])
            ->assertCreated();
    }

    public function test_pindah_mode_mengatur_rahasia_penanda_tangan(): void
    {
        $id = (string) $this->buat()->json('data.id');

        $keDorong = $this->actingAs($this->owner)->patchJson("/api/v1/integration-clients/{$id}", [
            ...$this->bentuk(), 'delivery_mode' => 'push', 'push_url' => 'https://finance.example.test/hook',
        ])->assertOk();
        $this->assertSame(48, strlen((string) $keDorong->json('signing_secret')));

        $this->actingAs($this->owner)->patchJson("/api/v1/integration-clients/{$id}", $this->bentuk())
            ->assertOk()->assertJsonPath('data.has_signing_secret', false)->assertJsonPath('data.push_url', null);
        $this->assertNull(IntegrationClient::query()->findOrFail($id)->signing_secret);
    }

    public function test_awalan_jenis_posting_dan_ejaan_bintang(): void
    {
        $id = (string) $this->buat(['posting_type_prefixes' => ['asset.*', ' cashier. ']])->json('data.id');
        $client = IntegrationClient::query()->findOrFail($id);

        $this->assertSame(['asset.', 'cashier.'], $client->posting_type_prefixes);
        $this->assertTrue($client->allowsPostingType('asset.acquisition'));
        $this->assertTrue($client->allowsPostingType('cashier.receipt'));
        $this->assertFalse($client->allowsPostingType('payroll.salary'));

        $semua = IntegrationClient::query()->findOrFail((string) $this->buat(['name' => 'Semua', 'posting_type_prefixes' => []])->json('data.id'));
        $this->assertTrue($semua->allowsPostingType('payroll.salary'));
    }

    public function test_hanya_owner_atau_admin_yang_melihat_dan_mengelola(): void
    {
        $id = (string) $this->buat()->json('data.id');
        $anggota = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id, 'system_role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($anggota)->get('/settings/integration-clients')->assertForbidden();
        $this->actingAs($anggota)->postJson('/api/v1/integration-clients', $this->bentuk())->assertForbidden();
        $this->actingAs($anggota)->postJson("/api/v1/integration-clients/{$id}/revoke")->assertForbidden();

        $this->actingAs($this->owner)->get('/settings/integration-clients')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/integration-clients')
                ->where('clients.0.id', $id)
                ->missing('clients.0.token_digest')
                ->missing('clients.0.signing_secret')
                ->where('scopes', fn ($cakupan): bool => isset($cakupan['finance-postings.read'])));
    }

    public function test_klien_tenant_lain_tidak_dapat_diubah(): void
    {
        $id = (string) $this->buat()->json('data.id');
        [$pemilikLain] = $this->tenantBaru('owner@lain.test', 'PT Lain');

        $this->actingAs($pemilikLain)->patchJson("/api/v1/integration-clients/{$id}", $this->bentuk())->assertNotFound();
        $this->actingAs($pemilikLain)->postJson("/api/v1/integration-clients/{$id}/rotate-token")->assertNotFound();
    }

    public function test_nama_klien_unik_per_tenant(): void
    {
        $this->buat(['name' => 'Old-finance'])->assertCreated();
        $this->buat(['name' => 'Old-finance'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    /** @return array<string, mixed> */
    private function bentuk(array $ubah = []): array
    {
        return [
            'name' => 'Old-finance',
            'delivery_mode' => 'pull',
            'push_url' => null,
            'scopes' => ['finance-postings.read', 'finance-postings.ack', 'vendors.read', 'operating-units.read'],
            'posting_type_prefixes' => ['asset.'],
            'allowed_ips' => [],
            ...$ubah,
        ];
    }

    private function buat(array $ubah = []): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', $this->bentuk($ubah));
    }

    private function unitsDengan(string $token): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/internal/v1/operating-units');
    }

    /** @return array{0: User, 1: TenantMembership} */
    private function tenantBaru(string $email, string $bisnis): array
    {
        $user = app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis, 'business_name' => $bisnis,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);

        return [$user, $user->activeMembership()];
    }

    private function unit(string $tenantId, string $nama, string $nomor): void
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $nama, 'classification' => 'operating_unit',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('operating_units')->insert([
            'organization_id' => $id, 'tenant_id' => $tenantId, 'type' => 'business_unit', 'number' => $nomor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
