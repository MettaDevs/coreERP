<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\AppServiceCredential;
use App\Models\ModuleInstallation;
use App\Models\Organization;
use App\Models\OrganizationHierarchyNode;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\DirektoriOrganisasi;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nomor operating unit sebagai kode dimensi keuangan (feed posting finance, area 1).
 *
 * Nomor inilah yang dikirim ke aplikasi finance sebagai nilai dimensi klinik dan poli, dan yang
 * disimpan pembaca di tabel penerjemahnya. Karena itu ia harus unik per tenant, tidak boleh hilang
 * diam-diam, dan business unit induknya harus bisa diturunkan dari hierarki manajemen yang berlaku
 * pada tanggal posting.
 */
class OperatingUnitNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = $this->pemilikBaru('owner@metta.test', 'PT Metta');
    }

    public function test_nomor_dirapikan_ke_huruf_besar_dan_tersimpan_bersama_salinan_tenant(): void
    {
        $unit = $this->operatingUnit('Klinik A', 'business_unit', ' kln-a ');

        $this->assertDatabaseHas('operating_units', [
            'organization_id' => $unit->id,
            'number' => 'KLN-A',
            'tenant_id' => $unit->tenant_id,
        ]);
    }

    public function test_nomor_unik_per_tenant_tetapi_boleh_sama_di_tenant_lain(): void
    {
        $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');

        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'operating_unit', 'name' => 'Klinik B',
            'operating_unit_type' => 'business_unit', 'operating_unit_number' => 'KLN-A',
        ])->assertSessionHasErrors('operating_unit_number');
        $this->assertDatabaseMissing('organizations', ['name' => 'Klinik B']);

        $pemilikLain = $this->pemilikBaru('owner@lain.test', 'PT Lain');
        $this->actingAs($pemilikLain)->post('/settings/organization/organizations', [
            'classification' => 'operating_unit', 'name' => 'Klinik Lain',
            'operating_unit_type' => 'business_unit', 'operating_unit_number' => 'KLN-A',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, DB::table('operating_units')->where('number', 'KLN-A')->count());
    }

    public function test_indeks_unik_menolak_nomor_ganda_yang_lolos_pemeriksaan_aplikasi(): void
    {
        $a = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $b = $this->operatingUnit('Klinik B', 'business_unit', null);

        // Dua permintaan yang lolos pemeriksaan bersamaan hanya dihentikan oleh indeks unik.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('operating_units')->where('organization_id', $b->id)->update([
            'tenant_id' => $a->tenant_id, 'number' => 'KLN-A',
        ]);
    }

    public function test_nomor_dengan_spasi_atau_tanda_baca_lain_ditolak(): void
    {
        foreach (['KLN A', 'KLN_A', '-KLN', 'KLN-', 'KLN--A', str_repeat('A', 31)] as $nomor) {
            $this->actingAs($this->owner)->post('/settings/organization/organizations', [
                'classification' => 'operating_unit', 'name' => 'Unit '.$nomor,
                'operating_unit_type' => 'department', 'operating_unit_number' => $nomor,
            ])->assertSessionHasErrors('operating_unit_number');
        }

        $this->assertSame(0, DB::table('operating_units')->count());
    }

    public function test_nomor_hanya_untuk_operating_unit(): void
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => 'PT Metta', 'company_code' => 'META',
            'country_code' => 'ID', 'operating_unit_number' => 'META',
        ])->assertSessionHasErrors('operating_unit_number');
    }

    public function test_mengubah_nama_tanpa_mengirim_nomor_tidak_menghapus_nomor(): void
    {
        $unit = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');

        $this->actingAs($this->owner)->patch("/settings/organization/organizations/{$unit->id}", [
            'name' => 'Poli Umum Lantai 2', 'operating_unit_type' => 'department',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('operating_units', ['organization_id' => $unit->id, 'number' => 'POLI-UMUM']);
        $this->assertDatabaseHas('organizations', ['id' => $unit->id, 'name' => 'Poli Umum Lantai 2']);
    }

    public function test_nomor_boleh_diganti_dan_dikosongkan(): void
    {
        $unit = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');

        $this->actingAs($this->owner)->patch("/settings/organization/organizations/{$unit->id}", [
            'name' => 'Poli Umum', 'operating_unit_type' => 'department', 'operating_unit_number' => 'PU-01',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('operating_units', ['organization_id' => $unit->id, 'number' => 'PU-01']);

        $this->patch("/settings/organization/organizations/{$unit->id}", [
            'name' => 'Poli Umum', 'operating_unit_type' => 'department', 'operating_unit_number' => '',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('operating_units', ['organization_id' => $unit->id, 'number' => null]);
    }

    public function test_nomor_milik_unit_lain_ditolak_saat_mengubah(): void
    {
        $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $b = $this->operatingUnit('Klinik B', 'business_unit', 'KLN-B');

        $this->actingAs($this->owner)->patch("/settings/organization/organizations/{$b->id}", [
            'name' => 'Klinik B', 'operating_unit_type' => 'business_unit', 'operating_unit_number' => 'KLN-A',
        ])->assertSessionHasErrors('operating_unit_number');

        $this->assertDatabaseHas('operating_units', ['organization_id' => $b->id, 'number' => 'KLN-B']);
    }

    public function test_unit_dari_rilis_sebelumnya_mendapat_salinan_tenant_saat_nomornya_diisi(): void
    {
        $unit = $this->operatingUnit('Poli Gigi', 'department', null);
        // Kode rilis sebelumnya tidak mengenal kolom ini dan meninggalkannya kosong.
        DB::table('operating_units')->where('organization_id', $unit->id)->update(['tenant_id' => null]);

        $this->actingAs($this->owner)->patch("/settings/organization/organizations/{$unit->id}", [
            'name' => 'Poli Gigi', 'operating_unit_type' => 'department', 'operating_unit_number' => 'POLI-GIGI',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('operating_units', [
            'organization_id' => $unit->id, 'number' => 'POLI-GIGI', 'tenant_id' => $unit->tenant_id,
        ]);
    }

    public function test_business_unit_induk_ditemukan_untuk_department_dua_tingkat_di_bawahnya(): void
    {
        $le = $this->legalEntity();
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $layanan = $this->operatingUnit('Layanan Medis', 'value_stream', null);
        $poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $this->hierarkiTerbit('Struktur manajemen', ['management'], $le, [
            [$klinik, $le], [$layanan, $klinik], [$poli, $layanan],
        ], '2026-01-01');

        $hasil = $this->direktori()->unitBisnisInduk($le->tenant_id, [$poli->id, $klinik->id], '2026-09-30');

        $this->assertSame(['id' => $klinik->id, 'nama' => 'Klinik A', 'nomor' => 'KLN-A'], $hasil[$poli->id]);
        // Business unit menurunkan dirinya sendiri.
        $this->assertSame($klinik->id, $hasil[$klinik->id]['id'] ?? null);
    }

    public function test_department_tanpa_business_unit_induk_menghasilkan_null(): void
    {
        $le = $this->legalEntity();
        $poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $lepas = $this->operatingUnit('Poli Lepas', 'department', 'POLI-LEPAS');
        $this->hierarkiTerbit('Struktur manajemen', ['management'], $le, [[$poli, $le]], '2026-01-01');

        $hasil = $this->direktori()->unitBisnisInduk($le->tenant_id, [$poli->id, $lepas->id], '2026-09-30');

        $this->assertSame([$poli->id => null, $lepas->id => null], $hasil);
    }

    public function test_hierarki_bertujuan_lain_dan_draft_tidak_dipakai(): void
    {
        $le = $this->legalEntity();
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $this->hierarkiTerbit('Struktur pengadaan', ['procurement'], $le, [[$klinik, $le], [$poli, $klinik]], '2026-01-01');
        $this->hierarki('Draft manajemen', ['management'], $le, [[$klinik, $le], [$poli, $klinik]], '2026-01-01');

        $hasil = $this->direktori()->unitBisnisInduk($le->tenant_id, [$poli->id], '2026-09-30');

        $this->assertNull($hasil[$poli->id]);
    }

    public function test_dua_hierarki_manajemen_yang_tidak_sepakat_tidak_ditebak(): void
    {
        $le = $this->legalEntity();
        $klinikA = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $klinikB = $this->operatingUnit('Klinik B', 'business_unit', 'KLN-B');
        $poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $this->hierarkiTerbit('Manajemen satu', ['management'], $le, [[$klinikA, $le], [$poli, $klinikA]], '2026-01-01');
        $this->hierarkiTerbit('Manajemen dua', ['management'], $le, [[$klinikB, $le], [$poli, $klinikB]], '2026-01-01');

        $this->assertNull($this->direktori()->unitBisnisInduk($le->tenant_id, [$poli->id], '2026-09-30')[$poli->id]);
    }

    public function test_business_unit_mengikuti_versi_hierarki_yang_berlaku_pada_tanggal_posting(): void
    {
        $le = $this->legalEntity();
        $klinikA = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $klinikB = $this->operatingUnit('Klinik B', 'business_unit', 'KLN-B');
        $poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $versiSatu = $this->hierarkiTerbit('Struktur manajemen', ['management'], $le, [
            [$klinikA, $le], [$klinikB, $le], [$poli, $klinikA],
        ], '2026-01-01');

        // Mulai 1 Oktober poli pindah ke Klinik B.
        $this->actingAs($this->owner)->post("/settings/organization/hierarchy-versions/{$versiSatu->id}/drafts", [
            'effective_from' => '2026-10-01',
        ])->assertSessionHasNoErrors();
        $versiDua = OrganizationHierarchyVersion::query()->where('status', 'draft')->firstOrFail();
        $node = OrganizationHierarchyNode::query()->where(['version_id' => $versiDua->id, 'organization_id' => $poli->id])->firstOrFail();
        $this->delete("/settings/organization/hierarchy-versions/{$versiDua->id}/placements/{$node->id}")->assertSessionHasNoErrors();
        $this->post("/settings/organization/hierarchy-versions/{$versiDua->id}/placements", [
            'organization_id' => $poli->id, 'parent_organization_id' => $klinikB->id,
        ])->assertSessionHasNoErrors();
        $this->post("/settings/organization/hierarchy-versions/{$versiDua->id}/publish")->assertSessionHasNoErrors();

        $direktori = $this->direktori();
        $this->assertSame('KLN-A', $direktori->unitBisnisInduk($le->tenant_id, [$poli->id], '2026-09-30')[$poli->id]['nomor'] ?? null);
        $this->assertSame('KLN-B', $direktori->unitBisnisInduk($le->tenant_id, [$poli->id], '2026-10-01')[$poli->id]['nomor'] ?? null);
    }

    public function test_business_unit_induk_tidak_menyeberang_tenant(): void
    {
        $le = $this->legalEntity();
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $this->hierarkiTerbit('Struktur manajemen', ['management'], $le, [[$klinik, $le], [$poli, $klinik]], '2026-01-01');
        $tenantLain = TenantMembership::query()->where('user_id', $this->pemilikBaru('lain@metta.test', 'PT Lain')->id)->value('tenant_id');

        $this->assertNull($this->direktori()->unitBisnisInduk((string) $tenantLain, [$poli->id], '2026-09-30')[$poli->id]);
    }

    public function test_unit_operasi_memulangkan_tipe_dan_nomor(): void
    {
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $this->operatingUnit('Poli Umum', 'department', null);

        $unit = $this->direktori()->unitOperasi($klinik->tenant_id);

        $this->assertSame([
            ['id' => $klinik->id, 'nama' => 'Klinik A', 'klasifikasi' => 'operating_unit', 'tipe' => 'business_unit', 'nomor' => 'KLN-A'],
        ], array_values(array_filter($unit, static fn (array $baris): bool => $baris['nama'] === 'Klinik A')));
        $this->assertNull(collect($unit)->firstWhere('nama', 'Poli Umum')['nomor']);
    }

    public function test_api_internal_memulangkan_nomor_dan_tipe_unit_aktif(): void
    {
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $tutup = $this->operatingUnit('Klinik Tutup', 'business_unit', 'KLN-X');
        DB::table('organizations')->where('id', $tutup->id)->update(['status' => 'inactive']);
        $this->legalEntity();

        $jawaban = $this->internal($klinik->tenant_id)->assertOk();

        $jawaban->assertJsonCount(1, 'data');
        $jawaban->assertJsonPath('data.0.id', $klinik->id);
        $jawaban->assertJsonPath('data.0.number', 'KLN-A');
        $jawaban->assertJsonPath('data.0.type', 'business_unit');
        $jawaban->assertJsonPath('data.0.status', 'active');
    }

    public function test_api_internal_updated_since_memuat_perubahan_nomor_dan_unit_nonaktif(): void
    {
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $poli = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $tutup = $this->operatingUnit('Klinik Tutup', 'business_unit', 'KLN-X');
        DB::table('organizations')->update(['updated_at' => '2026-09-01 00:00:00']);
        DB::table('operating_units')->update(['updated_at' => '2026-09-01 00:00:00']);

        // Nomor poli berubah (tercatat di operating_units), klinik tutup dinonaktifkan (organizations).
        DB::table('operating_units')->where('organization_id', $poli->id)->update(['number' => 'PU-01', 'updated_at' => '2026-09-22 03:00:00']);
        DB::table('organizations')->where('id', $tutup->id)->update(['status' => 'inactive', 'updated_at' => '2026-09-22 03:00:00']);

        // 10:00 WIB = 03:00 UTC. Batasnya inklusif, jadi kedua perubahan tepat pada batas ikut.
        $jawaban = $this->internal($klinik->tenant_id, ['updated_since' => '2026-09-22T10:00:00+07:00'])->assertOk();

        $jawaban->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$poli->id, $tutup->id], array_column($jawaban->json('data'), 'id'));
        $this->assertSame('inactive', collect($jawaban->json('data'))->firstWhere('id', $tutup->id)['status']);
        $this->assertSame('PU-01', collect($jawaban->json('data'))->firstWhere('id', $poli->id)['number']);

        $this->internal($klinik->tenant_id, ['updated_since' => '2026-09-22T10:00:01+07:00'])
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_api_internal_tidak_memulangkan_unit_tenant_lain(): void
    {
        $klinik = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $pemilikLain = $this->pemilikBaru('lain@metta.test', 'PT Lain');
        $tenantLain = (string) TenantMembership::query()->where('user_id', $pemilikLain->id)->value('tenant_id');
        $this->siapkanHr($tenantLain);

        $this->internal($tenantLain)->assertOk()->assertJsonCount(0, 'data');
        $this->internal($klinik->tenant_id)->assertOk()->assertJsonCount(1, 'data');
    }

    private function direktori(): DirektoriOrganisasi
    {
        return $this->app->make(DirektoriOrganisasi::class);
    }

    private function pemilikBaru(string $email, string $bisnis): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis,
            'business_name' => $bisnis,
            'app_ids' => ['app-uji'],
            'email' => $email,
            'password' => 'password',
        ]);
    }

    private function operatingUnit(string $nama, string $tipe, ?string $nomor): Organization
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', array_filter([
            'classification' => 'operating_unit',
            'name' => $nama,
            'operating_unit_type' => $tipe,
            'operating_unit_number' => $nomor,
        ], static fn (?string $nilai): bool => $nilai !== null))->assertSessionHasNoErrors();

        return Organization::query()->where('name', $nama)->latest('created_at')->firstOrFail();
    }

    private function legalEntity(): Organization
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => 'PT Metta Sehat', 'company_code' => 'META', 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return Organization::query()->where('name', 'PT Metta Sehat')->firstOrFail();
    }

    /**
     * @param  list<string>  $tujuan
     * @param  list<array{0: Organization, 1: Organization}>  $penempatan  Pasangan [anak, induk], urut dari atas.
     */
    private function hierarki(string $nama, array $tujuan, Organization $akar, array $penempatan, string $berlaku): OrganizationHierarchyVersion
    {
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => $nama, 'purpose_codes' => $tujuan,
            'root_organization_id' => $akar->id, 'effective_from' => $berlaku,
        ])->assertSessionHasNoErrors();
        $versi = OrganizationHierarchyVersion::query()
            ->whereHas('hierarchy', fn ($query) => $query->where('name', $nama))
            ->firstOrFail();
        foreach ($penempatan as [$anak, $induk]) {
            $this->post("/settings/organization/hierarchy-versions/{$versi->id}/placements", [
                'organization_id' => $anak->id, 'parent_organization_id' => $induk->id,
            ])->assertSessionHasNoErrors();
        }

        return $versi;
    }

    /**
     * @param  list<string>  $tujuan
     * @param  list<array{0: Organization, 1: Organization}>  $penempatan
     */
    private function hierarkiTerbit(string $nama, array $tujuan, Organization $akar, array $penempatan, string $berlaku): OrganizationHierarchyVersion
    {
        $versi = $this->hierarki($nama, $tujuan, $akar, $penempatan, $berlaku);
        $this->post("/settings/organization/hierarchy-versions/{$versi->id}/publish")->assertSessionHasNoErrors();

        return $versi->refresh();
    }

    /** @param array<string, string> $query */
    private function internal(string $tenantId, array $query = []): TestResponse
    {
        $this->siapkanHr($tenantId);

        return $this->withHeaders([
            'X-CoreERP-App-Id' => 'human-resources',
            'X-CoreERP-Service-Token' => 'secret-token',
            'X-CoreERP-Tenant-Id' => $tenantId,
        ])->getJson('/api/internal/v1/operating-units'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** Endpoint ini terbatas pada app human-resources yang berhak dan terpasang untuk tenant itu. */
    private function siapkanHr(string $tenantId): void
    {
        DB::table('apps')->insertOrIgnore(['id' => 'human-resources', 'name' => 'Human Resources', 'version' => '1.0.0', 'status' => 'available', 'database_name' => null, 'created_at' => now(), 'updated_at' => now()]);
        if (! AppServiceCredential::query()->where('app_id', 'human-resources')->exists()) {
            AppServiceCredential::query()->create(['app_id' => 'human-resources', 'name' => 'test', 'secret_hash' => Hash::make('secret-token'), 'status' => 'active']);
        }
        DB::table('tenant_app_entitlements')->insertOrIgnore(['tenant_id' => $tenantId, 'app_id' => 'human-resources', 'status' => 'active', 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('core_module_installations')->insertOrIgnore(['tenant_id' => $tenantId, 'module_id' => 'human-resources', 'version' => '1.0.0', 'status' => ModuleInstallation::STATUS_INSTALLED, 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
