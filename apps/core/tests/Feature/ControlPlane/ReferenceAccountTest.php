<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\FinanceReferenceAccount;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\DaftarAkun;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Daftar akun referensi milik aplikasi finance pelanggan (area 3, K-05).
 */
class ReferenceAccountTest extends TestCase
{
    use RefreshDatabase;

    private const COA = "external_id,code,name,type,active\n"
        ."1452,1-2300,Aset Tetap - Kendaraan,balance_sheet,true\n"
        ."1453,1-2390,Akumulasi Penyusutan - Kendaraan,balance_sheet,true\n"
        ."6510,6-5100,Beban Penyusutan Kendaraan,profit_loss,true\n";

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = $this->pemilik('owner@metta.test', 'PT Metta');
        $this->membership = $this->owner->activeMembership();
    }

    public function test_pratinjau_tidak_menulis_dan_penerapan_membuat_akun_serta_riwayat(): void
    {
        $pratinjau = $this->impor(self::COA, apply: false)->assertOk();
        $pratinjau->assertJsonPath('data.status', 'preview');
        $pratinjau->assertJsonCount(3, 'data.created');
        $this->assertDatabaseCount('finance_reference_accounts', 0);
        $this->assertDatabaseCount('finance_reference_account_imports', 0);

        $terap = $this->impor(self::COA)->assertOk();
        $terap->assertJsonPath('data.status', 'applied');
        $this->assertDatabaseCount('finance_reference_accounts', 3);
        $this->assertDatabaseHas('finance_reference_accounts', [
            'tenant_id' => $this->membership->tenant_id, 'legal_entity_id' => null,
            'external_id' => '6510', 'code' => '6-5100', 'type' => 'profit_loss', 'active' => true,
        ]);
        $this->assertDatabaseHas('finance_reference_account_imports', [
            'id' => $terap->json('data.import_id'), 'status' => 'applied', 'created_count' => 3,
            'imported_by_user_id' => (string) $this->owner->id,
        ]);
    }

    public function test_ganti_nama_dan_nomor_dengan_external_id_sama_mempertahankan_baris_yang_dipetakan(): void
    {
        $this->impor(self::COA);
        $id = FinanceReferenceAccount::query()->where('external_id', '1452')->value('id');

        $jawaban = $this->impor(str_replace('1-2300,Aset Tetap - Kendaraan', '1-2305,Kendaraan Operasional', self::COA))->assertOk();

        $jawaban->assertJsonPath('data.status', 'applied');
        $jawaban->assertJsonPath('data.updated.0.external_id', '1452');
        $jawaban->assertJsonPath('data.updated.0.changes.code.from', '1-2300');
        $jawaban->assertJsonPath('data.updated.0.changes.code.to', '1-2305');
        $jawaban->assertJsonPath('data.unchanged_count', 2);
        // Baris yang sama, bukan baris baru: pemetaan yang menunjuk id ini tetap utuh.
        $akun = $this->app->make(DaftarAkun::class)->satu($this->membership->tenant_id, (string) $id);
        $this->assertSame(['1-2305', 'Kendaraan Operasional'], [$akun['code'] ?? null, $akun['name'] ?? null]);
        $this->assertDatabaseCount('finance_reference_accounts', 3);
    }

    public function test_akun_yang_hilang_dari_berkas_dilaporkan_tetapi_tidak_dinonaktifkan(): void
    {
        $this->impor(self::COA);
        $lama = (string) FinanceReferenceAccount::query()->where('external_id', '1452')->value('id');

        // Di aplikasi finance akun 1452 dihapus lalu dibuat ulang dengan Akun_ID baru 9001.
        $baru = str_replace('1452,1-2300', '9001,1-2300', self::COA);
        $jawaban = $this->impor($baru)->assertOk();

        $jawaban->assertJsonPath('data.missing.0.external_id', '1452');
        $jawaban->assertJsonPath('data.created.0.external_id', '9001');
        $this->assertDatabaseHas('finance_reference_accounts', ['id' => $lama, 'active' => true]);

        // Pengguna yang memutuskan menonaktifkannya.
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/finance-reference-accounts/{$lama}", ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);

        $daftar = $this->app->make(DaftarAkun::class);
        $tenant = $this->membership->tenant_id;
        $this->assertNotContains('1452', array_column($daftar->cari($tenant, null), 'external_id'), 'Akun nonaktif tidak muncul di dropdown.');
        $this->assertFalse($daftar->satu($tenant, $lama)['active'] ?? true, 'Pemetaan lama tetap bisa menampilkan akun nonaktif.');
        $this->assertArrayHasKey($lama, $daftar->banyak($tenant, [$lama]));
    }

    public function test_satu_baris_salah_menolak_seluruh_berkas_dengan_nomor_barisnya(): void
    {
        $csv = "external_id,code,name,type,active\n"
            ."1452,1-2300,Aset Tetap - Kendaraan,balance_sheet,true\n"
            ."1453,,Akumulasi,balance_sheet,true\n"
            ."6510,6-5100,Beban,expense,true\n"
            ."7000,7-1000,Pendapatan lain,profit_loss,mungkin\n"
            ."2110,2-1100,Hutang Usaha,balance_sheet,true\n"
            ."2110,2-1101,Hutang Usaha Kembar,balance_sheet,true\n";

        $jawaban = $this->impor($csv)->assertOk();

        $jawaban->assertJsonPath('data.status', 'rejected');
        $baris = array_column($jawaban->json('data.rejected'), 'line');
        $this->assertSame([3, 4, 5, 6, 7], $baris);
        $this->assertStringContainsString('code wajib diisi', $jawaban->json('data.rejected.0.reason'));
        $this->assertStringContainsString('balance_sheet atau profit_loss', $jawaban->json('data.rejected.1.reason'));
        $this->assertStringContainsString('ganda', $jawaban->json('data.rejected.3.reason'));
        $this->assertDatabaseCount('finance_reference_accounts', 0);
        $this->assertDatabaseHas('finance_reference_account_imports', ['status' => 'rejected', 'rejected_count' => 5]);
    }

    public function test_berkas_excel_indonesia_dengan_titik_koma_bom_dan_kolom_tambahan_terbaca(): void
    {
        $csv = "\xEF\xBB\xBFExternal_ID;Code;Name;Type;Active;Keterangan\r\n"
            ."1452;1-2300;\"Aset Tetap; Kendaraan, Ambulans\";balance_sheet;ya;diabaikan\r\n"
            ."6510;6-5100;Beban Penyusutan;profit_loss;tidak;\r\n"
            ."\r\n";

        $this->impor($csv)->assertOk()->assertJsonPath('data.status', 'applied');

        $this->assertDatabaseHas('finance_reference_accounts', ['external_id' => '1452', 'name' => 'Aset Tetap; Kendaraan, Ambulans', 'active' => true]);
        $this->assertDatabaseHas('finance_reference_accounts', ['external_id' => '6510', 'active' => false]);
    }

    public function test_kolom_judul_yang_kurang_ditolak_dengan_nama_kolomnya(): void
    {
        $jawaban = $this->impor("external_id,code,name\n1452,1-2300,Aset\n")->assertOk();

        $jawaban->assertJsonPath('data.status', 'rejected');
        $this->assertStringContainsString('type, active', $jawaban->json('data.rejected.0.reason'));
    }

    public function test_akun_khusus_entitas_dan_akun_semua_entitas_tidak_boleh_kembar(): void
    {
        $le = $this->legalEntity('PT Metta Sehat', 'META');
        $this->impor("external_id,code,name,type,active\n1501,1-1500,PPN Masukan,balance_sheet,true\n", scope: $le)
            ->assertOk()->assertJsonPath('data.status', 'applied');
        $this->impor(self::COA)->assertOk()->assertJsonPath('data.status', 'applied');

        // external_id yang sudah terdaftar khusus entitas tidak boleh masuk lagi sebagai akun semua entitas.
        $this->impor("external_id,code,name,type,active\n1501,1-1500,PPN Masukan,balance_sheet,true\n")
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $daftar = $this->app->make(DaftarAkun::class);
        $tenant = $this->membership->tenant_id;
        $this->assertEqualsCanonicalizing(['1452', '1453', '1501', '6510'], array_column($daftar->cari($tenant, $le), 'external_id'));
        $this->assertEqualsCanonicalizing(['1452', '1453', '6510'], array_column($daftar->cari($tenant, null), 'external_id'));
        $this->assertSame(['6510'], array_column($daftar->cari($tenant, null, 'beban'), 'external_id'));
        $this->assertSame([], $daftar->cari($tenant, null, '%'), 'Persen di kata pencarian adalah huruf biasa.');
    }

    public function test_entitas_milik_tenant_lain_tidak_dapat_menjadi_cakupan(): void
    {
        $pemilikLain = $this->pemilik('owner@lain.test', 'PT Lain');
        $leLain = $this->legalEntity('PT Lain Sehat', 'LAIN', $pemilikLain);

        $this->impor(self::COA, scope: $leLain)->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->assertDatabaseCount('finance_reference_accounts', 0);
    }

    public function test_akun_tenant_lain_tidak_terlihat_dan_tidak_dapat_diubah(): void
    {
        $this->impor(self::COA);
        $pemilikLain = $this->pemilik('owner@lain.test', 'PT Lain');
        $this->actingAs($pemilikLain)->post('/api/v1/finance-reference-accounts/imports', [
            'file' => UploadedFile::fake()->createWithContent('akun.csv', "external_id,code,name,type,active\n9999,9-9999,Akun Lain,balance_sheet,true\n"),
            'scope' => 'all', 'apply' => '1',
        ])->assertOk();
        $akunLain = (string) FinanceReferenceAccount::query()->where('external_id', '9999')->value('id');

        $daftar = $this->app->make(DaftarAkun::class);
        $tenant = $this->membership->tenant_id;
        $this->assertNull($daftar->satu($tenant, $akunLain));
        $this->assertSame([], $daftar->banyak($tenant, [$akunLain]));
        $this->assertNotContains('9999', array_column($daftar->cari($tenant, null, '', 100), 'external_id'));

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/finance-reference-accounts/{$akunLain}", ['active' => false])
            ->assertNotFound();
        $this->actingAs($this->owner)->get('/settings/finance-accounts')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.total', 3));
    }

    public function test_anggota_biasa_dapat_melihat_tetapi_tidak_dapat_mengimpor_atau_mengubah(): void
    {
        $this->impor(self::COA);
        $anggota = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id, 'system_role' => 'member', 'status' => 'active',
        ]);
        $id = (string) FinanceReferenceAccount::query()->value('id');

        $this->actingAs($anggota)->get('/settings/finance-accounts')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
        $this->actingAs($anggota)->post('/api/v1/finance-reference-accounts/imports', [
            'file' => UploadedFile::fake()->createWithContent('akun.csv', self::COA), 'scope' => 'all', 'apply' => '1',
        ])->assertForbidden();
        $this->actingAs($anggota)->patchJson("/api/v1/finance-reference-accounts/{$id}", ['active' => false])->assertForbidden();

        $this->assertDatabaseHas('finance_reference_accounts', ['id' => $id, 'active' => true]);
    }

    public function test_halaman_menyaring_dan_menampilkan_riwayat_impor(): void
    {
        $this->impor(self::COA);
        $this->impor("external_id,code\n1,2\n");

        $this->actingAs($this->owner)->get('/settings/finance-accounts?q=penyusutan&status=active')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/finance-accounts')
                ->where('canManage', true)
                ->where('accounts.total', 2)
                ->where('imports.0.status', 'rejected')
                ->where('imports.1.status', 'applied')
                ->where('imports.1.imported_by', 'Owner PT Metta')
                ->where('header', ['external_id', 'code', 'name', 'type', 'active']));
    }

    public function test_templat_csv_berisi_baris_judul_saja(): void
    {
        $jawaban = $this->actingAs($this->owner)->get('/settings/finance-accounts/template')->assertOk();

        $this->assertSame("external_id,code,name,type,active\r\n", $jawaban->getContent());
        $this->assertStringContainsString('text/csv', (string) $jawaban->headers->get('Content-Type'));
    }

    public function test_database_menolak_external_id_kembar_dan_jenis_yang_tidak_dikenal(): void
    {
        $this->impor(self::COA);

        $gagal = 0;
        foreach ([
            ['external_id' => '1452', 'type' => 'balance_sheet'],
            ['external_id' => '8888', 'type' => 'expense'],
        ] as $baris) {
            try {
                DB::transaction(fn () => DB::table('finance_reference_accounts')->insert([
                    'id' => (string) str()->ulid(), 'tenant_id' => $this->membership->tenant_id, 'legal_entity_id' => null,
                    'external_id' => $baris['external_id'], 'code' => 'X', 'name' => 'X', 'type' => $baris['type'],
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]));
            } catch (QueryException) {
                $gagal++;
            }
        }

        $this->assertSame(2, $gagal);
    }

    private function impor(string $csv, bool $apply = true, string $scope = 'all'): TestResponse
    {
        return $this->actingAs($this->owner)->post('/api/v1/finance-reference-accounts/imports', [
            'file' => UploadedFile::fake()->createWithContent('akun.csv', $csv),
            'scope' => $scope,
            'apply' => $apply ? '1' : '0',
        ], ['Accept' => 'application/json']);
    }

    private function pemilik(string $email, string $bisnis): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$bisnis, 'business_name' => $bisnis,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function legalEntity(string $nama, string $kode, ?User $pemilik = null): string
    {
        $this->actingAs($pemilik ?? $this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $nama, 'company_code' => $kode, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return (string) DB::table('organizations')->where('name', $nama)->value('id');
    }
}
