<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\ModuleInstallation;
use App\Support\Modules\ModuleMigrator;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Data awal module diisi tepat sekali seumur pemasangan.
 *
 * Ini rangkaian yang paling mudah salah dan paling mahal bila salah: pelanggan yang
 * menonaktifkan module lalu mengaktifkannya lagi mendapat master bawaan dobel, dan yang
 * membersihkan dobelnya harus menebak mana yang asli.
 */
class ModuleSeedTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::ulid();
        $registry = $this->app->make(ModuleRegistry::class);
        $migrator = $this->app->make(ModuleMigrator::class);

        foreach (['contoh-a', 'contoh-b'] as $id) {
            $module = $registry->cari($id);
            $this->assertNotNull($module);
            $migrator->naik($module);
        }
    }

    public function test_memasang_module_a_tidak_mengisi_data_module_b(): void
    {
        $this->catatPemasangan('contoh-a');
        $this->catatPemasangan('contoh-b');

        $this->isiDataAwal('contoh-a');

        $this->assertSame(2, $this->jumlahBaris('contoh_a_m_barang'));
        $this->assertSame(0, $this->jumlahBaris('contoh_b_m_rak'), 'Data awal module B tidak boleh ikut terisi.');
    }

    public function test_menonaktifkan_lalu_mengaktifkan_lagi_tidak_menambah_baris_bawaan(): void
    {
        $this->catatPemasangan('contoh-a');
        $this->assertTrue($this->isiDataAwal('contoh-a'), 'Pemanggilan pertama harus benar-benar mengisi.');
        $setelahPertama = $this->jumlahBaris('contoh_a_m_barang');

        $this->ubahStatus('contoh-a', ModuleInstallation::STATUS_DISABLED);
        $this->ubahStatus('contoh-a', ModuleInstallation::STATUS_INSTALLED);

        $this->assertFalse($this->isiDataAwal('contoh-a'), 'Pemanggilan kedua harus dilewati karena seeded_at sudah terisi.');
        $this->assertSame($setelahPertama, $this->jumlahBaris('contoh_a_m_barang'));
    }

    public function test_data_buatan_pengguna_selamat_sepanjang_rangkaian(): void
    {
        $this->catatPemasangan('contoh-a');
        $this->isiDataAwal('contoh-a');

        DB::table('contoh_a_m_barang')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'kode' => 'BRG-PENGGUNA',
            'nama' => 'Diketik pengguna',
            'bawaan' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ubahStatus('contoh-a', ModuleInstallation::STATUS_DISABLED);
        $this->ubahStatus('contoh-a', ModuleInstallation::STATUS_UNINSTALLED);
        $this->ubahStatus('contoh-a', ModuleInstallation::STATUS_INSTALLED);
        $this->isiDataAwal('contoh-a');

        $this->assertSame(1, DB::table('contoh_a_m_barang')
            ->where('tenant_id', $this->tenantId)
            ->where('kode', 'BRG-PENGGUNA')
            ->count());
        $this->assertSame(2, DB::table('contoh_a_m_barang')
            ->where('tenant_id', $this->tenantId)
            ->where('bawaan', true)
            ->count(), 'Baris bawaan tidak boleh bertambah walau module sempat dicabut.');
    }

    public function test_baris_bawaan_bisa_dibedakan_dari_baris_pengguna(): void
    {
        $this->catatPemasangan('contoh-a');
        $this->isiDataAwal('contoh-a');

        $this->assertSame(
            2,
            DB::table('contoh_a_m_barang')->where('tenant_id', $this->tenantId)->where('bawaan', true)->count(),
        );
        $this->assertSame(
            0,
            DB::table('contoh_a_m_barang')->where('tenant_id', $this->tenantId)->where('bawaan', false)->count(),
        );
    }

    public function test_seed_module_tidak_dijalankan_bila_tidak_ada_catatan_pemasangan(): void
    {
        $this->assertFalse(
            $this->isiDataAwal('contoh-a'),
            'Tanpa catatan pemasangan, tidak ada yang bisa menandai bahwa data awal sudah diisi.'
        );
        $this->assertSame(0, $this->jumlahBaris('contoh_a_m_barang'));
    }

    private function isiDataAwal(string $moduleId): bool
    {
        $module = $this->app->make(ModuleRegistry::class)->cari($moduleId);
        $this->assertNotNull($module);

        return $this->app->make(ModuleSeeder::class)->jalankan($module, $this->tenantId);
    }

    private function catatPemasangan(string $moduleId): void
    {
        DB::table('core_module_installations')->insert([
            'tenant_id' => $this->tenantId,
            'module_id' => $moduleId,
            'version' => '0.1.0',
            'status' => ModuleInstallation::STATUS_INSTALLED,
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ubahStatus(string $moduleId, string $status): void
    {
        DB::table('core_module_installations')
            ->where('tenant_id', $this->tenantId)
            ->where('module_id', $moduleId)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    private function jumlahBaris(string $tabel): int
    {
        return DB::table($tabel)->where('tenant_id', $this->tenantId)->count();
    }
}
