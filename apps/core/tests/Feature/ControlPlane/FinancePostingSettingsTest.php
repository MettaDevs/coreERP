<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\CurrencyPrecision;
use App\Models\FinanceSettlementMode;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Finance\MoneyPrecision;
use App\Support\Modules\Contracts\PresisiMataUang;
use App\Support\Modules\Contracts\SetelanPostingFinance;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/**
 * Setelan feed posting per entitas legal dan presisi uang per mata uang (area 5, K-10, K-16, K-20).
 */
class FinancePostingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'], 'email' => 'owner@metta.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();
    }

    public function test_entitas_tanpa_setelan_memakai_direct_payable_tanpa_cutover_dan_feed_mati(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');

        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$le}/finance-posting")
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.cutover_date', null)
            ->assertJsonPath('data.current_mode', 'direct_payable')
            ->assertJsonPath('data.modes', []);

        $setelan = $this->app->make(SetelanPostingFinance::class);
        $this->assertSame('direct_payable', $setelan->modePenyelesaian($le, '2026-09-22'));
        $this->assertNull($setelan->cutover($le));
    }

    public function test_mode_terbaca_sesuai_tanggal_berlaku(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');
        $this->tambahMode($le, 'clearing', '2026-01-01')->assertCreated();
        $this->tambahMode($le, 'direct_payable', '2026-07-01')->assertCreated();

        $setelan = $this->app->make(SetelanPostingFinance::class);
        // Sebelum baris pertama berlaku, bawaan yang dipakai.
        $this->assertSame('direct_payable', $setelan->modePenyelesaian($le, '2025-12-31'));
        $this->assertSame('clearing', $setelan->modePenyelesaian($le, '2026-01-01'));
        $this->assertSame('clearing', $setelan->modePenyelesaian($le, '2026-06-30'));
        $this->assertSame('direct_payable', $setelan->modePenyelesaian($le, '2026-07-01'));
    }

    public function test_tanggal_berlaku_tidak_boleh_ganda(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');
        $this->tambahMode($le, 'clearing', '2026-01-01')->assertCreated();

        $this->tambahMode($le, 'direct_payable', '2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');
        $this->assertSame(1, FinanceSettlementMode::query()->where('legal_entity_id', $le)->count());
    }

    public function test_mode_yang_tidak_dikenal_ditolak_aplikasi_dan_database(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');

        $this->tambahMode($le, 'cash', '2026-01-01')->assertStatus(422)->assertJsonValidationErrors('mode');

        $this->expectException(QueryException::class);
        DB::table('finance_settlement_modes')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->membership->tenant_id, 'legal_entity_id' => $le,
            'mode' => 'cash', 'effective_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_mode_yang_belum_berlaku_boleh_dihapus_tetapi_yang_sudah_berlaku_tidak(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');
        $this->tambahMode($le, 'clearing', today()->subDay()->toDateString())->assertCreated();
        $this->tambahMode($le, 'direct_payable', today()->addMonth()->toDateString())->assertCreated();
        $lampau = FinanceSettlementMode::query()->whereDate('effective_from', '<', today())->value('id');
        $nanti = FinanceSettlementMode::query()->whereDate('effective_from', '>', today())->value('id');

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/organizations/{$le}/finance-posting/settlement-modes/{$lampau}")
            ->assertStatus(422);
        $this->deleteJson("/api/v1/organizations/{$le}/finance-posting/settlement-modes/{$nanti}")
            ->assertNoContent();

        $this->assertDatabaseHas('finance_settlement_modes', ['id' => $lampau]);
        $this->assertDatabaseMissing('finance_settlement_modes', ['id' => $nanti]);
    }

    public function test_feed_tidak_dapat_diaktifkan_tanpa_cutover(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');

        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$le}/finance-posting", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cutover_date');

        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$le}/finance-posting", [
            'enabled' => true, 'cutover_date' => '2026-10-01',
        ])->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.cutover_date', '2026-10-01');

        $this->assertSame('2026-10-01', $this->app->make(SetelanPostingFinance::class)->cutover($le));

        // Database menjaga aturan yang sama untuk jalur yang tidak lewat layar.
        $this->expectException(QueryException::class);
        DB::table('finance_posting_settings')->where('legal_entity_id', $le)->update(['cutover_date' => null]);
    }

    public function test_hanya_owner_atau_admin_yang_mengubah_dan_anggota_tetap_dapat_membaca(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');
        $anggota = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id, 'system_role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($anggota)->getJson("/api/v1/organizations/{$le}/finance-posting")->assertOk();
        $this->actingAs($anggota)->putJson("/api/v1/organizations/{$le}/finance-posting", [
            'enabled' => true, 'cutover_date' => '2026-10-01',
        ])->assertForbidden();
        $this->actingAs($anggota)->postJson("/api/v1/organizations/{$le}/finance-posting/settlement-modes", [
            'mode' => 'clearing', 'effective_from' => '2026-10-01',
        ])->assertForbidden();

        $this->assertDatabaseCount('finance_posting_settings', 0);
        $this->assertDatabaseCount('finance_settlement_modes', 0);
    }

    public function test_entitas_tenant_lain_dan_operating_unit_tidak_dapat_disetel(): void
    {
        $unit = $this->operatingUnit('Klinik A');
        $pemilikLain = app(RegisterBusiness::class)->handle([
            'name' => 'Lain', 'business_name' => 'PT Lain',
            'app_ids' => ['app-uji'], 'email' => 'owner@lain.test', 'password' => 'password',
        ]);
        $leLain = $this->legalEntity('PT Lain Sehat', 'LAIN', $pemilikLain);

        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$leLain}/finance-posting")->assertNotFound();
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$leLain}/finance-posting", ['enabled' => false])->assertNotFound();
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$unit}/finance-posting")->assertNotFound();
    }

    public function test_kontrak_menolak_entitas_legal_yang_tidak_ada(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->app->make(SetelanPostingFinance::class)->modePenyelesaian((string) Str::ulid(), '2026-09-22');
    }

    public function test_pembulatan_nol_dan_dua_desimal_termasuk_nilai_negatif(): void
    {
        $this->assertSame('1234.57', MoneyPrecision::round('1234.565', 2));
        $this->assertSame('1234.56', MoneyPrecision::round('1234.5649', 2));
        $this->assertSame('-1234.57', MoneyPrecision::round('-1234.565', 2), 'Nilai di tengah menjauhi nol, juga untuk nilai negatif.');
        $this->assertSame('0.01', MoneyPrecision::round('0.005', 2));
        $this->assertSame('-0.01', MoneyPrecision::round('-0.005', 2));
        $this->assertSame('1235', MoneyPrecision::round('1234.5', 0));
        $this->assertSame('-1235', MoneyPrecision::round('-1234.5', 0));
        $this->assertSame('500000000.00', MoneyPrecision::round('500000000', 2), 'Skala hasilnya selalu persis.');
        $this->assertSame('500000000', MoneyPrecision::round('500000000.00', 0));
        // Float diterima, tetapi tidak kehilangan sen pada nilai besar.
        $this->assertSame('987654321.99', MoneyPrecision::round(987654321.985, 2));
        $this->assertSame(2, MoneyPrecision::scale('10.50'));
        $this->assertSame(0, MoneyPrecision::scale('10'));
    }

    public function test_tiga_baris_berharga_satuan_berdesimal_tetap_seimbang_bila_dibulatkan_per_baris(): void
    {
        $presisi = $this->app->make(PresisiMataUang::class);
        $tenant = $this->membership->tenant_id;
        $hargaSatuan = '333333.333';

        // Dibulatkan per baris di sumber (K-20), lalu hutang disusun dari nilai yang sudah bulat.
        $baris = array_map(fn (): string => $presisi->bulatkan($tenant, $hargaSatuan, 'IDR'), range(1, 3));
        $debit = MoneyPrecision::sum(...$baris);
        $kredit = MoneyPrecision::round(MoneyPrecision::sum(...$baris), $presisi->nilai($tenant, 'IDR'));

        $this->assertSame(['333333.33', '333333.33', '333333.33'], $baris);
        $this->assertSame('999999.99', $debit);
        $this->assertSame($debit, $kredit);
        // Membulatkan totalnya lebih dulu menghasilkan angka lain — asal selisih yang tidak bisa ditelusuri.
        $this->assertSame('1000000.00', MoneyPrecision::round(MoneyPrecision::sum($hargaSatuan, $hargaSatuan, $hargaSatuan), 2));
    }

    public function test_presisi_idr_bawaan_dan_dapat_disetel_per_tenant(): void
    {
        $presisi = $this->app->make(PresisiMataUang::class);
        $tenant = $this->membership->tenant_id;
        $this->assertSame(2, $presisi->nilai($tenant, 'IDR'));
        $this->assertSame(3, $presisi->hargaSatuan($tenant, 'idr'));

        $this->actingAs($this->owner)->put('/settings/currencies/IDR', [
            'amount_decimals' => 0, 'unit_amount_decimals' => 3,
        ])->assertSessionHasNoErrors();

        $presisi = $this->app->make(PresisiMataUang::class);
        $this->assertSame(0, $presisi->nilai($tenant, 'IDR'));
        $this->assertSame('500000001', $presisi->bulatkan($tenant, '500000000.50', 'IDR'));

        // Tenant lain tetap memakai bawaan.
        $this->assertSame(2, $presisi->nilai((string) Str::ulid(), 'IDR'));
    }

    public function test_presisi_harga_satuan_tidak_boleh_lebih_kasar_dari_presisi_nilai(): void
    {
        $this->actingAs($this->owner)->put('/settings/currencies/IDR', [
            'amount_decimals' => 2, 'unit_amount_decimals' => 1,
        ])->assertSessionHasErrors('unit_amount_decimals');
        $this->actingAs($this->owner)->put('/settings/currencies/IDR', [
            'amount_decimals' => 5, 'unit_amount_decimals' => 6,
        ])->assertSessionHasErrors('amount_decimals');
        $this->actingAs($this->owner)->put('/settings/currencies/USD', [
            'amount_decimals' => 2, 'unit_amount_decimals' => 3,
        ])->assertNotFound();

        $this->assertDatabaseCount('currency_precisions', 0);

        $this->expectException(QueryException::class);
        CurrencyPrecision::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'currency_code' => 'IDR',
            'amount_decimals' => 3, 'unit_amount_decimals' => 2,
        ]);
    }

    public function test_mata_uang_tanpa_setelan_dan_tanpa_bawaan_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum disetel');

        $this->app->make(PresisiMataUang::class)->nilai($this->membership->tenant_id, 'JPY');
    }

    public function test_halaman_mata_uang_menampilkan_idr_bawaan(): void
    {
        $this->actingAs($this->owner)->get('/settings/currencies')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/currencies')
                ->where('canManage', true)
                ->where('currencies.0.code', 'IDR')
                ->where('currencies.0.amount_decimals', 2)
                ->where('currencies.0.unit_amount_decimals', 3)
                ->where('currencies.0.is_default', true));
    }

    public function test_anggota_biasa_tidak_dapat_mengubah_presisi(): void
    {
        $anggota = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id, 'system_role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($anggota)->put('/settings/currencies/IDR', [
            'amount_decimals' => 0, 'unit_amount_decimals' => 3,
        ])->assertForbidden();
        $this->assertDatabaseCount('currency_precisions', 0);
    }

    private function tambahMode(string $le, string $mode, string $berlaku): TestResponse
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$le}/finance-posting/settlement-modes", [
            'mode' => $mode, 'effective_from' => $berlaku,
        ]);
    }

    private function legalEntity(string $nama, string $kode, ?User $pemilik = null): string
    {
        $this->actingAs($pemilik ?? $this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $nama, 'company_code' => $kode, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return (string) DB::table('organizations')->where('name', $nama)->value('id');
    }

    private function operatingUnit(string $nama): string
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'operating_unit', 'name' => $nama, 'operating_unit_type' => 'business_unit',
        ])->assertSessionHasNoErrors();

        return (string) DB::table('organizations')->where('name', $nama)->value('id');
    }
}
