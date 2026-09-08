<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Support\Modules\ModuleMigrator;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration module berjalan sendiri, dan riwayatnya tidak menumpang milik Core.
 *
 * Kalau menumpang, mencabut sebuah module lalu memasangnya lagi akan melewati semua
 * migration-nya karena riwayatnya masih tercatat, dan tabelnya tidak pernah dibuat ulang.
 */
class ModuleMigratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_module_membuat_tabel_berawalan_miliknya(): void
    {
        $module = $this->app->make(ModuleRegistry::class)->cari('contoh-a');
        $this->assertNotNull($module);

        $this->app->make(ModuleMigrator::class)->naik($module);

        $this->assertTrue($this->tabelAda('contoh_a_m_barang'));
    }

    public function test_menjalankan_dua_kali_tidak_mengulang_migration_yang_sama(): void
    {
        $module = $this->app->make(ModuleRegistry::class)->cari('contoh-a');
        $this->assertNotNull($module);
        $migrator = $this->app->make(ModuleMigrator::class);

        $pertama = $migrator->naik($module);
        $kedua = $migrator->naik($module);

        $this->assertNotEmpty($pertama, 'Jalan pertama harus benar-benar menjalankan sesuatu.');
        $this->assertSame([], $kedua, 'Jalan kedua tidak boleh mengulang migration yang sudah tercatat.');
        $this->assertCount(count($pertama), $migrator->sudahJalan($module));
    }

    public function test_riwayat_module_tidak_menambah_tabel_migrations_milik_core(): void
    {
        $module = $this->app->make(ModuleRegistry::class)->cari('contoh-a');
        $this->assertNotNull($module);

        $sebelum = DB::table('migrations')->count();
        $this->app->make(ModuleMigrator::class)->naik($module);
        $sesudah = DB::table('migrations')->count();

        $this->assertSame($sebelum, $sesudah, 'Migration module tidak boleh tercatat di riwayat milik Core.');
        $this->assertTrue($this->tabelAda(ModuleMigrator::TABEL_RIWAYAT));
    }

    public function test_riwayat_dua_module_tidak_saling_menutupi(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);
        $migrator = $this->app->make(ModuleMigrator::class);

        $a = $registry->cari('contoh-a');
        $b = $registry->cari('contoh-b');
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $migrator->naik($a);
        $migrator->naik($b);

        $this->assertTrue($this->tabelAda('contoh_a_m_barang'));
        $this->assertTrue($this->tabelAda('contoh_b_m_rak'));
        $this->assertSame(
            ['contoh-a', 'contoh-b'],
            DB::table(ModuleMigrator::TABEL_RIWAYAT)->distinct()->orderBy('module_id')->pluck('module_id')->all(),
        );
    }

    public function test_perintah_menolak_module_yang_tidak_dikenal(): void
    {
        $keluar = Artisan::call('module:migrate', ['module' => 'tidak-ada']);

        $this->assertSame(1, $keluar);
        $this->assertStringContainsString('tidak ditemukan', Artisan::output());
    }

    public function test_perintah_menjalankan_migration_module_contoh(): void
    {
        $keluar = Artisan::call('module:migrate', ['module' => 'contoh-b']);

        $this->assertSame(0, $keluar);
        $this->assertTrue($this->tabelAda('contoh_b_m_rak'));
    }

    private function tabelAda(string $tabel): bool
    {
        return DB::table('information_schema.tables')
            ->whereRaw('table_schema = current_schema()')
            ->where('table_name', $tabel)
            ->exists();
    }
}
