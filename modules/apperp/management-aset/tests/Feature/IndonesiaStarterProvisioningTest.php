<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use App\Support\Modules\Contracts\TenantDisiapkan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class IndonesiaStarterProvisioningTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    public function test_event_tenant_baru_aman_diulang_dan_tidak_menyeberang_tenant(): void
    {
        // Tenant dibuat lewat trait supaya urutan nomornya ikut disiapkan; penyediaan data awal
        // menerbitkan nomor sungguhan, dan tenant tanpa urutan nomor ditolak Core.
        $tenant = $this->buatTenantUji();
        $otherTenant = $this->buatTenantUji();

        $this->pancarkan($tenant);

        $this->assertDatabaseCount('aset_m_kelompok_harta_fiskal', 7);
        $this->assertDatabaseCount('aset_m_profil_penyusutan', 10);
        $this->assertDatabaseCount('aset_m_buku_penyusutan', 2);
        $this->assertDatabaseCount('aset_m_tipe_lokasi_aset', 6);
        $this->assertDatabaseCount('aset_m_kondisi_aset', 5);
        $this->assertDatabaseCount('aset_m_pabrikan_aset', 68);
        $this->assertDatabaseCount('aset_m_model_aset', 209);
        $this->assertDatabaseHas('aset_m_pabrikan_aset', [
            'tenant_id' => $tenant,
            'creation_key' => 'pabrikan-aset:starter:id:manufacturer-models:indonesia-asia:v1:toyota',
            'nama' => 'Toyota',
            'aktif' => true,
        ]);
        $this->assertDatabaseHas('aset_m_model_aset', [
            'tenant_id' => $tenant,
            'creation_key' => 'model-aset:starter:id:manufacturer-models:indonesia-asia:v1:toyota:avanza',
            'nama' => 'Avanza',
            'jenis_aset_id' => null,
            'model_number' => null,
            'aktif' => true,
        ]);
        $this->assertDatabaseMissing('aset_m_pabrikan_aset', ['tenant_id' => $otherTenant]);
        $this->assertDatabaseMissing('aset_m_model_aset', ['tenant_id' => $otherTenant]);
        $this->assertDatabaseCount('aset_m_maintenance_job_type', 7);
        $this->assertDatabaseCount('aset_m_maintenance_job_type_variant', 42);
        $this->assertDatabaseCount('aset_m_maintenance_checklist_variable', 1);
        $this->assertDatabaseCount('aset_m_maintenance_checklist_variable_value', 3);
        $this->assertDatabaseCount('aset_m_maintenance_checklist_template', 1);
        $this->assertDatabaseCount('aset_m_maintenance_checklist_template_line', 4);
        $this->assertDatabaseCount('aset_m_maintenance_job_type_default', 2);
        $this->assertDatabaseCount('aset_m_sebab_kerusakan', 0);
        $this->assertDatabaseCount('aset_m_tindakan_perbaikan', 0);
        $this->assertDatabaseHas('aset_m_kelompok_harta_fiskal', [
            'tenant_id' => $tenant,
            'template_key' => 'id:pmk72-2023:kelompok-1:v1',
            'useful_life_years' => 4,
            'straight_line_rate_percent' => 25,
            'reducing_balance_rate_percent' => 50,
        ]);
        $this->assertDatabaseHas('aset_m_buku_penyusutan', [
            'tenant_id' => $tenant,
            'creation_key' => 'buku-penyusutan:starter:id:pmk72-2023:buku:fiskal:v1',
            'posting_layer' => 'tax',
            'export_to_backoffice' => false,
        ]);
        $this->assertDatabaseHas('aset_m_buku_penyusutan', [
            'tenant_id' => $tenant,
            'creation_key' => 'buku-penyusutan:starter:id:pmk72-2023:buku:komersial:v1',
            'posting_layer' => 'current',
            'depreciation_profile_id' => null,
            'export_to_backoffice' => false,
        ]);

        $classificationId = (string) DB::table('aset_m_kelompok_harta_fiskal')
            ->where(['tenant_id' => $tenant, 'template_key' => 'id:pmk72-2023:kelompok-1:v1'])
            ->value('id');
        DB::table('aset_m_group_aset')->insert([
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
        $defaultBookId = (string) DB::table('aset_m_buku_penyusutan')
            ->where(['tenant_id' => $tenant, 'posting_layer' => 'current'])
            ->value('id');
        $profileId = (string) DB::table('aset_m_profil_penyusutan')
            ->where('creation_key', 'profil-penyusutan:starter:id:pmk72-2023:profil:kelompok-1:garis-lurus:v1')
            ->value('id');

        // Dihitung **sebelum** event diulang: yang dijaga adalah pengulangan tidak menerbitkan
        // nomor baru. Dulu ini dihitung dari jumlah permintaan HTTP; sekarang dari baris
        // penerbitan Core, yang membuktikan lebih banyak — permintaan bisa saja terkirim ulang
        // tanpa menerbitkan apa pun, dan itu justru yang benar.
        $sebelumDiulang = $this->jumlahNomorTerbit();

        $this->pancarkan($tenant);

        $this->assertSame($sebelumDiulang, $this->jumlahNomorTerbit(), 'Pengulangan event menerbitkan nomor baru.');
        $this->assertDatabaseCount('aset_m_kelompok_harta_fiskal', 7);
        $this->assertDatabaseCount('aset_m_profil_penyusutan', 10);
        $this->assertDatabaseCount('aset_m_buku_penyusutan', 2);
        $this->assertDatabaseCount('aset_m_group_buku_penyusutan', 1);
        $this->assertDatabaseHas('aset_m_group_buku_penyusutan', [
            'tenant_id' => $tenant,
            'buku_id' => $defaultBookId,
            'depreciation_profile_id' => $profileId,
        ]);
        $this->assertDatabaseCount('aset_m_tipe_lokasi_aset', 6);
        $this->assertDatabaseCount('aset_m_kondisi_aset', 5);
        $this->assertDatabaseCount('aset_m_pabrikan_aset', 68);
        $this->assertDatabaseCount('aset_m_model_aset', 209);
        $this->assertDatabaseCount('aset_m_maintenance_job_type', 7);
        $this->assertDatabaseCount('aset_m_maintenance_job_type_variant', 42);
        $this->assertDatabaseCount('aset_m_maintenance_checklist_variable_value', 3);
        $this->assertDatabaseCount('aset_m_maintenance_checklist_template_line', 4);
        $this->assertDatabaseCount('aset_m_maintenance_job_type_default', 2);
        $this->assertDatabaseMissing('aset_m_kelompok_harta_fiskal', ['tenant_id' => $otherTenant]);
    }

    /**
     * Pintu HTTP-nya benar-benar tertutup, bukan sekadar tidak dipakai lagi.
     *
     * Rute lama memverifikasi tanda tangan HMAC, dan permintaan tanpa tanda tangan dijawab 401.
     * Sesudah penyediaan menjadi event di dalam proses, rutenya dihapus — tetapi "dihapus" dan
     * "masih ada tetapi tidak dipanggil siapa-siapa" terlihat sama persis dari kode. Yang
     * membedakannya hanya pemeriksaan seperti ini.
     */
    public function test_endpoint_provisioning_lama_sudah_tidak_ada(): void
    {
        $this->postJson('/api/modules/management-aset/internal/v1/provisioning/tenant', [
            'id' => (string) Str::ulid(),
            'type' => 'core.tenant.provisioned.v1',
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => (string) Str::ulid(),
            'correlation_id' => (string) Str::ulid(),
            'data' => ['app_ids' => ['management-aset']],
        ])->assertNotFound();
    }

    public function test_event_untuk_module_lain_dilewati_tanpa_data_separuh(): void
    {
        $this->pancarkan((string) Str::ulid(), ['human-resources']);

        $this->assertSame(0, $this->jumlahNomorTerbit(), 'Ada nomor yang terbit padahal seharusnya tidak.');
        $this->assertDatabaseCount('aset_m_kelompok_harta_fiskal', 0);
        $this->assertDatabaseCount('aset_m_profil_penyusutan', 0);
        $this->assertDatabaseCount('aset_m_buku_penyusutan', 0);
    }

    /**
     * Memancarkan event tenant baru dengan tenant aktif terikat, seperti Core memancarkannya.
     *
     * Ikatan tenantnya bukan hiasan: listener menyemai lewat model module, dan penyaringan
     * tenant model gagal-menutup — tanpa ikatan itu penyediaan melempar, bukan menyemai ke
     * tenant yang salah. `event()` telanjang di sini akan membuat test menempuh keadaan yang
     * tidak pernah dipakai produksi.
     *
     * Yang dipakai `PelaksanaUntukTenant`, sebuah kontrak, bukan `PengirimEventModul` milik
     * Core. Keduanya melakukan hal yang sama, dan itu memang kelemahan yang diterima sadar:
     * test ini jadi meniru pengirimnya alih-alih memanggilnya, sehingga perubahan pada
     * pengirim tidak akan terlihat di sini. Yang menutup celah itu test pendaftaran usaha di
     * Core, yang memanggil pengirim sungguhan. Sebagai gantinya, seluruh berkas module berhenti
     * menyebut kelas Core di luar kontrak — syarat modul ini keluar dari daftar pemindahan.
     *
     * @param  list<string>  $appIds
     */
    private function pancarkan(string $tenantId, array $appIds = ['management-aset']): void
    {
        app(PelaksanaUntukTenant::class)->jalankanUntuk(
            $tenantId,
            static fn (): bool => event(
                new TenantDisiapkan((string) Str::ulid(), $tenantId, $tenantId, null, ['app_ids' => $appIds]),
            ) !== null,
        );
    }
}
