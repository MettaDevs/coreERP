<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Platform\Organization\Models\Organization;
use App\Platform\Tenant\Actions\RegisterBusiness;
use Carbon\CarbonImmutable;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Zona waktu dan tanggal kerja pengguna (area 7 TODO analisa gap BC fase 1, B-7): "hari ini" dihitung dari
 * jam server menurut zona pengguna, zona bawaannya dari entitas legal aktif (K-10), dan tanggal kerja hidup
 * selama sesi lalu kembali ke hari ini saat login ulang atau pindah entitas legal (K-04).
 */
final class DateTimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);

        // 00.30 WIB tanggal 29 adalah 17.30 UTC tanggal 28. Ditetapkan sebelum pendaftaran, supaya penugasan
        // peran owner sudah berlaku pada jam itu.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 17:30:00', 'UTC'));
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner PT Metta', 'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'], 'email' => 'owner@metta.test', 'password' => 'password',
        ]);

        // Organisasi yang dapat dipilih bergantung pada cakupan kebijakan data; owner uji diberi cakupan
        // tanpa batas organisasi, seperti owner tenant yang memasang module sungguhan.
        DB::table('app_data_policies')->insert([
            'code' => 'app-uji.organisasi', 'app_id' => 'app-uji', 'name' => 'Akses organisasi uji',
            'protected_permissions' => json_encode([], JSON_THROW_ON_ERROR), 'requires_legal_entity' => false,
            'requires_operating_unit' => false, 'allows_descendants' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $membership = $this->owner->activeMembership();
        RoleAssignment::query()->where('membership_id', $membership?->id)->firstOrFail()->dataPolicyScopes()->create([
            'tenant_id' => $membership?->tenant_id, 'policy_code' => 'app-uji.organisasi', 'include_descendants' => false, 'valid_from' => now(),
        ]);
    }

    public function test_hari_ini_mengikuti_zona_pengguna_bukan_tanggal_utc(): void
    {
        $this->owner->forceFill(['timezone' => 'Asia/Jakarta'])->save();

        $this->actingAs($this->owner)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page
            ->where('clock.timezone', 'Asia/Jakarta')
            ->where('clock.today', '2026-09-29')
            ->where('workDate.value', null)
            ->where('dateTime.timezone', 'Asia/Jakarta'));
    }

    public function test_pengguna_tanpa_zona_mengikuti_entitas_legal_aktif(): void
    {
        $this->legalEntity('PT Metta Makassar', 'MKS', 'Asia/Makassar');

        $this->actingAs($this->owner)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page
            ->where('clock.timezone', 'Asia/Makassar')
            ->where('clock.today', '2026-09-29')
            ->where('dateTime.timezone', null)
            ->where('dateTime.legal_entity_timezone', 'Asia/Makassar'));

        // Tanpa entitas legal maupun pilihan pengguna, zona aplikasi (UTC) yang dipakai.
        $lain = app(RegisterBusiness::class)->handle([
            'name' => 'Owner Lain', 'business_name' => 'PT Lain',
            'app_ids' => ['app-uji'], 'email' => 'owner@lain.test', 'password' => 'password',
        ]);
        $this->actingAs($lain)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page
            ->where('clock.timezone', 'UTC')
            ->where('clock.today', '2026-09-28'));
    }

    public function test_zona_pengguna_disimpan_divalidasi_dan_dapat_kembali_ikut_entitas_legal(): void
    {
        $this->legalEntity('PT Metta Makassar', 'MKS', 'Asia/Makassar');
        $this->actingAs($this->owner);

        $this->patch('/settings/date-time', ['timezone' => 'Bukan/Zona'])->assertSessionHasErrors(['timezone' => 'Pilih zona waktu dari daftar.']);
        $this->patch('/settings/date-time', ['timezone' => 'Asia/Jayapura'])->assertSessionHasNoErrors();
        $this->assertSame('Asia/Jayapura', $this->owner->fresh()?->timezone);

        $this->patch('/settings/date-time', ['timezone' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->owner->fresh()?->timezone);
        $this->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->where('clock.timezone', 'Asia/Makassar'));
    }

    public function test_tanggal_kerja_hidup_di_sesi_dan_pengingatnya_dapat_ditutup(): void
    {
        $this->owner->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $this->actingAs($this->owner);

        $this->patch('/settings/date-time', ['work_date' => '0226-10-31'])
            ->assertSessionHasErrors(['work_date' => 'Tanggal kerja terlalu jauh ke belakang. Periksa tahunnya.']);
        $this->patch('/settings/date-time', ['work_date' => '2026-10-31'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('workDate.value', '2026-10-31')
            ->where('workDate.notice_dismissed', false));

        $this->post('/settings/date-time/dismiss-work-date-notice')->assertRedirect();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('workDate.notice_dismissed', true));

        // Tanggal baru adalah keputusan baru: pengingatnya muncul lagi.
        $this->patch('/settings/date-time', ['work_date' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('workDate.value', '2026-11-30')
            ->where('workDate.notice_dismissed', false));

        // Mengisi hari ini sama dengan kembali ke hari ini: besok ia ikut bergeser.
        $this->patch('/settings/date-time', ['work_date' => '2026-09-29'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('workDate.value', null));
    }

    public function test_tanggal_kerja_kembali_ke_hari_ini_saat_pindah_entitas_legal_dan_login_ulang(): void
    {
        $pertama = $this->legalEntity('PT Metta Jakarta', 'JKT', 'Asia/Jakarta');
        $kedua = $this->legalEntity('PT Metta Makassar', 'MKS', 'Asia/Makassar');
        $membership = $this->owner->activeMembership();
        $this->actingAs($this->owner);

        $this->putJson('/api/v1/workspace-context', ['membership_id' => $membership?->id, 'legal_entity_id' => $pertama])->assertOk();
        $this->patch('/settings/date-time', ['work_date' => '2026-10-31'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('workDate.value', '2026-10-31'));

        $this->putJson('/api/v1/workspace-context', ['membership_id' => $membership?->id, 'legal_entity_id' => $kedua])->assertOk();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('workDate.value', null)
            ->where('clock.timezone', 'Asia/Makassar'));

        $this->patch('/settings/date-time', ['work_date' => '2026-10-31'])->assertSessionHasNoErrors();
        $this->post('/logout');
        Auth::forgetGuards();
        $this->post(route('login.store'), ['email' => 'owner@metta.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($this->owner);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('workDate.value', null));
    }

    public function test_zona_waktu_entitas_legal_disimpan_dan_hanya_untuk_entitas_legal(): void
    {
        $this->actingAs($this->owner);
        $id = $this->legalEntity('PT Metta Makassar', 'MKS', 'Asia/Makassar');
        $this->assertDatabaseHas('legal_entities', ['organization_id' => $id, 'timezone' => 'Asia/Makassar']);

        $this->patch("/settings/organization/organizations/{$id}", [
            'version' => DB::table('organizations')->where('id', $id)->value('version'),
            'classification' => 'legal_entity', 'name' => 'PT Metta Makassar', 'company_code' => 'MKS', 'country_code' => 'ID',
            'timezone' => 'Asia/Jayapura',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('legal_entities', ['organization_id' => $id, 'timezone' => 'Asia/Jayapura']);

        $this->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => 'PT Salah', 'company_code' => 'SLH', 'country_code' => 'ID', 'timezone' => 'Bukan/Zona',
        ])->assertSessionHasErrors(['timezone' => 'Pilih zona waktu dari daftar.']);
        $this->post('/settings/organization/organizations', [
            'classification' => 'operating_unit', 'name' => 'Poli', 'operating_unit_type' => 'department', 'timezone' => 'Asia/Jakarta',
        ])->assertSessionHasErrors(['timezone' => 'Zona waktu hanya untuk entitas legal.']);

        // Tanpa pilihan, bawaan kolomnya yang berlaku.
        $this->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => 'PT Tanpa Zona', 'company_code' => 'TZN', 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();
        $tanpa = (string) Organization::query()->where('name', 'PT Tanpa Zona')->value('id');
        $this->assertDatabaseHas('legal_entities', ['organization_id' => $tanpa, 'timezone' => 'Asia/Jakarta']);
    }

    private function legalEntity(string $nama, string $kode, string $zona): string
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $nama, 'company_code' => $kode, 'country_code' => 'ID', 'timezone' => $zona,
        ])->assertSessionHasNoErrors();

        return (string) Organization::query()->where('name', $nama)->value('id');
    }
}
