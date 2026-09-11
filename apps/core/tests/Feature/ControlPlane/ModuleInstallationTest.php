<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\ModuleInstallation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Catatan pemasangan module adalah satu-satunya sumber kebenaran untuk pertanyaan
 * "module apa yang terpasang untuk tenant ini". Test ini menjaga tiga janji yang
 * masing-masing sudah pernah gagal di tempat lain: pemasangan berulang tidak menggandakan
 * baris, mengaktifkan kembali tidak mengisi ulang data awal, dan pencabutan tidak
 * membuang jejak bahwa data awal pernah diisi.
 */
class ModuleInstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_memasang_dua_kali_tidak_membuat_baris_kedua(): void
    {
        $tenantId = (string) Str::ulid();

        $this->pasang($tenantId, 'contoh-a');
        $this->pasang($tenantId, 'contoh-a');

        $this->assertSame(1, ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', 'contoh-a')
            ->count());
    }

    public function test_mengaktifkan_kembali_tidak_mengisi_ulang_data_awal(): void
    {
        $tenantId = (string) Str::ulid();
        $this->pasang($tenantId, 'contoh-a');

        $this->tandaiSudahDiisiDataAwal($tenantId, 'contoh-a');
        $seedPertama = $this->baris($tenantId, 'contoh-a')->seeded_at;
        $this->assertNotNull($seedPertama);

        $this->ubahStatus($tenantId, 'contoh-a', ModuleInstallation::STATUS_DISABLED, 'disabled_at');
        $this->pasang($tenantId, 'contoh-a');

        $baris = $this->baris($tenantId, 'contoh-a');
        $this->assertTrue($baris->sedangAktif());
        $this->assertTrue($baris->sudahDiisiDataAwal(), 'Data awal harus tetap tercatat pernah diisi.');
        $this->assertTrue(
            $seedPertama->equalTo($baris->seeded_at),
            'seeded_at berubah, jadi data awal akan terisi dua kali saat module diaktifkan lagi.'
        );
    }

    public function test_pencabutan_menyimpan_barisnya_beserta_jejak_data_awal(): void
    {
        $tenantId = (string) Str::ulid();
        $this->pasang($tenantId, 'contoh-a');
        $this->tandaiSudahDiisiDataAwal($tenantId, 'contoh-a');

        $this->ubahStatus($tenantId, 'contoh-a', ModuleInstallation::STATUS_UNINSTALLED, 'uninstalled_at');

        $baris = $this->baris($tenantId, 'contoh-a');
        $this->assertNotNull($baris, 'Baris pemasangan tidak boleh hilang saat module dicabut.');
        $this->assertFalse($baris->sedangAktif());
        $this->assertTrue(
            $baris->sudahDiisiDataAwal(),
            'Tanpa jejak ini, berlangganan ulang akan mengisi data awal di atas data lama yang tidak pernah dihapus.'
        );
    }

    public function test_satu_module_terpasang_pada_dua_tenant_berdiri_sendiri(): void
    {
        $tenantSatu = (string) Str::ulid();
        $tenantDua = (string) Str::ulid();

        $this->pasang($tenantSatu, 'contoh-a');
        $this->pasang($tenantDua, 'contoh-a');
        $this->ubahStatus($tenantSatu, 'contoh-a', ModuleInstallation::STATUS_DISABLED, 'disabled_at');

        $this->assertFalse($this->baris($tenantSatu, 'contoh-a')->sedangAktif());
        $this->assertTrue($this->baris($tenantDua, 'contoh-a')->sedangAktif());
    }

    public function test_status_di_luar_tiga_yang_sah_ditolak_database(): void
    {
        $tenantId = (string) Str::ulid();
        $this->pasang($tenantId, 'contoh-a');

        $this->expectException(QueryException::class);

        DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->where('module_id', 'contoh-a')
            ->update(['status' => 'dinonaktifkan']);
    }

    private function pasang(string $tenantId, string $moduleId): void
    {
        DB::table('core_module_installations')->upsert([[
            'tenant_id' => $tenantId,
            'module_id' => $moduleId,
            'version' => '0.1.0',
            'status' => ModuleInstallation::STATUS_INSTALLED,
            'installed_at' => now(),
            'disabled_at' => null,
            'uninstalled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['tenant_id', 'module_id'], ['version', 'status', 'disabled_at', 'uninstalled_at', 'updated_at']);
    }

    private function tandaiSudahDiisiDataAwal(string $tenantId, string $moduleId): void
    {
        DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->update(['seeded_at' => now(), 'updated_at' => now()]);
    }

    private function ubahStatus(string $tenantId, string $moduleId, string $status, string $kolomWaktu): void
    {
        DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->update(['status' => $status, $kolomWaktu => now(), 'updated_at' => now()]);
    }

    private function baris(string $tenantId, string $moduleId): ?ModuleInstallation
    {
        return ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->first();
    }
}
