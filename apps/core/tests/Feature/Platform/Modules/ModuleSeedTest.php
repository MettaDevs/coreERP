<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Modules;

use App\Platform\Modules\Models\ModuleInstallation;
use App\Platform\Modules\Support\ModuleMigrator;
use App\Platform\Modules\Support\ModuleRegistry;
use App\Platform\Modules\Support\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
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
            $module = $registry->find($id);
            $this->assertNotNull($module);
            $migrator->migrate($module);
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

    public function test_failed_seed_rolls_back_partial_data_and_can_be_retried(): void
    {
        $this->catatPemasangan('contoh-a');
        $injected = false;
        DB::listen(function ($query) use (&$injected): void {
            if (! $injected && str_starts_with($query->sql, 'insert into "contoh_a_m_barang"')) {
                $injected = true;

                throw new RuntimeException('Pengisian data awal terputus setelah baris pertama.');
            }
        });

        try {
            $this->isiDataAwal('contoh-a');
            $this->fail('Kegagalan pengisian harus diteruskan ke pemanggil.');
        } catch (RuntimeException $error) {
            $this->assertSame('Pengisian data awal terputus setelah baris pertama.', $error->getMessage());
        }

        $this->assertTrue($injected);
        $this->assertSame(0, $this->jumlahBaris('contoh_a_m_barang'));
        $this->assertNull(ModuleInstallation::query()
            ->where('tenant_id', $this->tenantId)
            ->where('module_id', 'contoh-a')
            ->firstOrFail()->seeded_at);
        $this->assertTrue($this->isiDataAwal('contoh-a'));
        $this->assertSame(2, $this->jumlahBaris('contoh_a_m_barang'));
        $this->assertFalse($this->isiDataAwal('contoh-a'));
    }

    private function isiDataAwal(string $moduleId): bool
    {
        $module = $this->app->make(ModuleRegistry::class)->find($moduleId);
        $this->assertNotNull($module);

        return $this->app->make(ModuleSeeder::class)->run($module, $this->tenantId);
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
