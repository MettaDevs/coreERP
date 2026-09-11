<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Models\AppServiceCredential;
use App\Models\FiscalCalendar;
use App\Models\ModuleInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Kalender tahun buku dibaca app lewat internal API, bukan disalin ke database app.
 * Salinan lokal akan menyimpang begitu tenant mengubah kalendernya.
 */
class FiscalCalendarDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $appId = 'sample-app';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Sample Client', 'slug' => 'sample-client-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $this->tenantId, 'client_id' => $clientId, 'name' => 'Sample Tenant', 'slug' => 'sample-tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('apps')->insert(['id' => $this->appId, 'name' => 'Sample app', 'version' => '1.0.0', 'status' => 'available', 'database_name' => 'sample_app', 'created_at' => now(), 'updated_at' => now()]);
        $this->readyApp();
        AppServiceCredential::query()->create(['app_id' => $this->appId, 'name' => 'test', 'secret_hash' => Hash::make('secret-token'), 'status' => 'active']);
    }

    public function test_mengembalikan_tahun_buku_dan_periode_yang_memuat_tanggal(): void
    {
        // Tahun buku sengaja mulai Juli agar tidak tertukar dengan tahun kalender.
        $legalEntity = $this->legalEntityWithFiscalCalendar(startMonth: 7);

        $response = $this->internal(['legal_entity_id' => $legalEntity, 'date' => '2026-08-15'])->assertOk();

        $response->assertJsonPath('data.year.starts_on', '2026-07-01');
        $response->assertJsonPath('data.year.ends_on', '2027-06-30');
        $response->assertJsonPath('data.period.starts_on', '2026-08-01');
        $response->assertJsonPath('data.period.ends_on', '2026-08-31');
        $response->assertJsonPath('data.period.ordinal', 2);
        $response->assertJsonPath('data.calendar.code', 'CAL-LE1');
    }

    public function test_tanggal_di_awal_dan_akhir_tahun_buku_tetap_terpetakan(): void
    {
        $legalEntity = $this->legalEntityWithFiscalCalendar(startMonth: 7);

        $this->internal(['legal_entity_id' => $legalEntity, 'date' => '2026-07-01'])
            ->assertOk()->assertJsonPath('data.period.ordinal', 1);
        $this->internal(['legal_entity_id' => $legalEntity, 'date' => '2027-06-30'])
            ->assertOk()->assertJsonPath('data.period.ordinal', 12);
    }

    public function test_tanggal_di_luar_kalender_dijawab_404_bukan_tebakan(): void
    {
        $legalEntity = $this->legalEntityWithFiscalCalendar(startMonth: 7);

        $this->internal(['legal_entity_id' => $legalEntity, 'date' => '2020-01-15'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'fiscal_period_not_found');
    }

    public function test_legal_entity_tanpa_kalender_dijawab_404(): void
    {
        $organizationId = $this->organization('legal_entity', 'LE2');
        DB::table('legal_entities')->insert([
            'organization_id' => $organizationId, 'tenant_id' => $this->tenantId,
            'company_code' => 'LE2', 'country_code' => 'ID', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->internal(['legal_entity_id' => $organizationId, 'date' => '2026-08-15'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'fiscal_calendar_not_assigned');
    }

    public function test_kalender_tenant_lain_tidak_dapat_dibaca(): void
    {
        $legalEntity = $this->legalEntityWithFiscalCalendar(startMonth: 7);
        $otherTenant = (string) Str::ulid();
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $otherTenant, 'client_id' => $clientId, 'name' => 'Other Tenant', 'slug' => 'other-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->readyApp($otherTenant);

        // Kredensial app sama, tenant berbeda: kalender milik tenant pertama harus hilang.
        $this->withHeaders([
            'X-CoreERP-App-Id' => $this->appId,
            'X-CoreERP-Service-Token' => 'secret-token',
            'X-CoreERP-Tenant-Id' => $otherTenant,
        ])->getJson('/api/internal/v1/fiscal-periods?legal_entity_id='.$legalEntity.'&date=2026-08-15')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'fiscal_calendar_not_assigned');
    }

    public function test_permintaan_tanpa_kredensial_app_ditolak(): void
    {
        $this->getJson('/api/internal/v1/fiscal-periods?legal_entity_id='.Str::ulid().'&date=2026-08-15')
            ->assertUnauthorized();
    }

    public function test_parameter_wajib_divalidasi(): void
    {
        $this->internal(['legal_entity_id' => 'bukan-ulid', 'date' => '15-08-2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legal_entity_id', 'date']);
    }

    /** @param array<string, string> $query */
    private function internal(array $query): TestResponse
    {
        return $this->withHeaders([
            'X-CoreERP-App-Id' => $this->appId,
            'X-CoreERP-Service-Token' => 'secret-token',
            'X-CoreERP-Tenant-Id' => $this->tenantId,
        ])->getJson('/api/internal/v1/fiscal-periods?'.http_build_query($query));
    }

    private function legalEntityWithFiscalCalendar(int $startMonth): string
    {
        $organizationId = $this->organization('legal_entity', 'LE1');
        DB::table('legal_entities')->insert([
            'organization_id' => $organizationId, 'tenant_id' => $this->tenantId,
            'company_code' => 'LE1', 'country_code' => 'ID', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $calendar = FiscalCalendar::query()->create(['tenant_id' => $this->tenantId, 'code' => 'CAL-LE1', 'name' => 'Kalender LE1']);
        $fiscal = app(FiscalCalendarService::class);
        $start = Carbon::create(2026, $startMonth, 1)->startOfDay();
        $fiscal->defineYear($calendar, 'FY2027', $start, $start->copy()->addYear()->subDay(), $fiscal->monthlyPeriods($start));
        DB::table('legal_entities')->where('organization_id', $organizationId)->update(['fiscal_calendar_id' => $calendar->id]);

        return $organizationId;
    }

    private function organization(string $classification, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'name' => $code, 'classification' => $classification, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * Sebuah app siap bagi satu tenant bila tenant itu berhak atasnya **dan** modulenya
     * tercatat terpasang untuknya. Keduanya per tenant, jadi keduanya ditulis per tenant.
     */
    private function readyApp(?string $tenantId = null): void
    {
        $tenantId ??= $this->tenantId;
        DB::table('tenant_app_entitlements')->insert(['tenant_id' => $tenantId, 'app_id' => $this->appId, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('core_module_installations')->insert(['tenant_id' => $tenantId, 'module_id' => $this->appId, 'version' => '1.0.0', 'status' => ModuleInstallation::STATUS_INSTALLED, 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
