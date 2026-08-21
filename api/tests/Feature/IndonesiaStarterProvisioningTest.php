<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndonesiaStarterProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private string $signingKey = 'test-context-signing-key-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.coreerp.context_signing_key' => $this->signingKey,
            'services.coreerp.app_id' => 'management-aset',
            'services.coreerp.url' => 'http://core.test',
            'services.coreerp.service_token' => 'service-token',
        ]);
    }

    public function test_signed_tenant_event_is_idempotent_and_does_not_cross_tenants(): void
    {
        $calls = 0;
        Http::fake(function (HttpRequest $request) use (&$calls) {
            $calls++;

            return Http::response(['data' => ['number' => 'SEED'.str_pad((string) $calls, 5, '0', STR_PAD_LEFT)]], 200);
        });

        $tenant = (string) Str::ulid();
        $otherTenant = (string) Str::ulid();
        $body = $this->eventBody($tenant);

        $this->call('POST', '/api/internal/v1/provisioning/tenant', [], [], [], $this->eventServer($body), $body)
            ->assertOk()
            ->assertJsonPath('data.template_key', 'id:pmk72-2023:starter:v1');

        $this->assertDatabaseCount('m_kelompok_harta_fiskal', 7);
        $this->assertDatabaseCount('m_profil_penyusutan', 10);
        $this->assertDatabaseCount('m_buku_penyusutan', 2);
        $this->assertDatabaseCount('m_tipe_lokasi_aset', 6);
        $this->assertDatabaseCount('m_kondisi_aset', 5);
        $this->assertDatabaseCount('m_pabrikan_aset', 68);
        $this->assertDatabaseCount('m_model_aset', 209);
        $this->assertDatabaseHas('m_pabrikan_aset', [
            'tenant_id' => $tenant,
            'creation_key' => 'pabrikan-aset:starter:id:manufacturer-models:indonesia-asia:v1:toyota',
            'nama' => 'Toyota',
            'aktif' => true,
        ]);
        $this->assertDatabaseHas('m_model_aset', [
            'tenant_id' => $tenant,
            'creation_key' => 'model-aset:starter:id:manufacturer-models:indonesia-asia:v1:toyota:avanza',
            'nama' => 'Avanza',
            'jenis_aset_id' => null,
            'model_number' => null,
            'aktif' => true,
        ]);
        $this->assertDatabaseMissing('m_pabrikan_aset', ['tenant_id' => $otherTenant]);
        $this->assertDatabaseMissing('m_model_aset', ['tenant_id' => $otherTenant]);
        $this->assertDatabaseCount('m_maintenance_job_type', 7);
        $this->assertDatabaseCount('m_maintenance_job_type_variant', 42);
        $this->assertDatabaseCount('m_maintenance_checklist_variable', 1);
        $this->assertDatabaseCount('m_maintenance_checklist_variable_value', 3);
        $this->assertDatabaseCount('m_maintenance_checklist_template', 1);
        $this->assertDatabaseCount('m_maintenance_checklist_template_line', 4);
        $this->assertDatabaseCount('m_maintenance_job_type_default', 2);
        $this->assertDatabaseCount('m_sebab_kerusakan', 0);
        $this->assertDatabaseCount('m_tindakan_perbaikan', 0);
        $this->assertDatabaseHas('m_kelompok_harta_fiskal', [
            'tenant_id' => $tenant,
            'template_key' => 'id:pmk72-2023:kelompok-1:v1',
            'useful_life_years' => 4,
            'straight_line_rate_percent' => 25,
            'reducing_balance_rate_percent' => 50,
        ]);
        $this->assertDatabaseHas('m_buku_penyusutan', [
            'tenant_id' => $tenant,
            'creation_key' => 'buku-penyusutan:starter:id:pmk72-2023:buku:fiskal:v1',
            'posting_layer' => 'tax',
            'export_to_backoffice' => false,
        ]);
        $this->assertDatabaseHas('m_buku_penyusutan', [
            'tenant_id' => $tenant,
            'creation_key' => 'buku-penyusutan:starter:id:pmk72-2023:buku:komersial:v1',
            'posting_layer' => 'current',
            'depreciation_profile_id' => null,
            'export_to_backoffice' => false,
        ]);

        $classificationId = (string) DB::table('m_kelompok_harta_fiskal')
            ->where(['tenant_id' => $tenant, 'template_key' => 'id:pmk72-2023:kelompok-1:v1'])
            ->value('id');
        DB::table('m_group_aset')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant,
            'creation_key' => 'group-starter-test',
            'kode' => 'GSTART',
            'nama' => 'Group uji starter',
            'kelompok_harta_fiskal_id' => $classificationId,
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Matriks starter memasang buku komersial, bukan fiskal: tenant baru belum tentu
        // meminta pembukuan pajak, dan buku pertamanya dipakai sebagai dasar pelaporan.
        $defaultBookId = (string) DB::table('m_buku_penyusutan')
            ->where(['tenant_id' => $tenant, 'posting_layer' => 'current'])
            ->value('id');
        $profileId = (string) DB::table('m_profil_penyusutan')
            ->where('creation_key', 'profil-penyusutan:starter:id:pmk72-2023:profil:kelompok-1:garis-lurus:v1')
            ->value('id');

        $this->call('POST', '/api/internal/v1/provisioning/tenant', [], [], [], $this->eventServer($body), $body)
            ->assertOk();

        $this->assertSame(365, $calls, 'Pengulangan event tidak boleh meminta nomor baru.');
        $this->assertDatabaseCount('m_kelompok_harta_fiskal', 7);
        $this->assertDatabaseCount('m_profil_penyusutan', 10);
        $this->assertDatabaseCount('m_buku_penyusutan', 2);
        $this->assertDatabaseCount('m_group_buku_penyusutan', 1);
        $this->assertDatabaseHas('m_group_buku_penyusutan', [
            'tenant_id' => $tenant,
            'buku_id' => $defaultBookId,
            'depreciation_profile_id' => $profileId,
        ]);
        $this->assertDatabaseCount('m_tipe_lokasi_aset', 6);
        $this->assertDatabaseCount('m_kondisi_aset', 5);
        $this->assertDatabaseCount('m_pabrikan_aset', 68);
        $this->assertDatabaseCount('m_model_aset', 209);
        $this->assertDatabaseCount('m_maintenance_job_type', 7);
        $this->assertDatabaseCount('m_maintenance_job_type_variant', 42);
        $this->assertDatabaseCount('m_maintenance_checklist_variable_value', 3);
        $this->assertDatabaseCount('m_maintenance_checklist_template_line', 4);
        $this->assertDatabaseCount('m_maintenance_job_type_default', 2);
        $this->assertDatabaseMissing('m_kelompok_harta_fiskal', ['tenant_id' => $otherTenant]);
    }

    public function test_unsigned_provisioning_request_is_rejected(): void
    {
        $this->postJson('/api/internal/v1/provisioning/tenant', [
            'id' => (string) Str::ulid(),
            'type' => 'core.tenant.provisioned.v1',
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'data' => ['app_ids' => ['management-aset']],
        ])->assertUnauthorized();
    }

    public function test_event_for_another_app_is_acknowledged_without_partial_seed(): void
    {
        Http::fake();
        $body = $this->eventBody((string) Str::ulid(), ['human-resources']);

        $this->call('POST', '/api/internal/v1/provisioning/tenant', [], [], [], $this->eventServer($body), $body)
            ->assertOk()
            ->assertJsonPath('data.skipped', true);

        Http::assertNothingSent();
        $this->assertDatabaseCount('m_kelompok_harta_fiskal', 0);
        $this->assertDatabaseCount('m_profil_penyusutan', 0);
        $this->assertDatabaseCount('m_buku_penyusutan', 0);
    }

    /** @param list<string> $appIds */
    private function eventBody(string $tenantId, array $appIds = ['management-aset']): string
    {
        return json_encode([
            'id' => (string) Str::ulid(),
            'type' => 'core.tenant.provisioned.v1',
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => $tenantId,
            'correlation_id' => $tenantId,
            'data' => ['app_ids' => $appIds],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function eventServer(string $body): array
    {
        $timestamp = (string) now()->timestamp;

        return [
            'HTTP_X_COREERP_EVENT_TIMESTAMP' => $timestamp,
            'HTTP_X_COREERP_EVENT_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $this->signingKey),
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
