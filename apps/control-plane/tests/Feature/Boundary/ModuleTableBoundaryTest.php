<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\TableOwnershipInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Penjaga pertama: migration sebuah module hanya boleh membuat tabel berawalan miliknya.
 *
 * Batas ini **tidak** ditegakkan mesin database. Semua tabel berada di satu database dan
 * satu koneksi, jadi tidak ada yang menghalangi migration module membuat tabel bernama
 * `core_tenants`. Yang menegakkannya adalah test ini. Ia harus disebut apa adanya supaya
 * tidak ada yang merasa aman tanpa alasan.
 */
class ModuleTableBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tabel yang boleh lahir dari migration module walau tidak berawalan miliknya.
     *
     * Ditulis di sini, bukan di berkas setelan, supaya pengecualian baru terlihat pada diff.
     * `migrations` adalah buku catatan milik Laravel sendiri dan lahir sekali saja.
     *
     * @var list<string>
     */
    private const PENGECUALIAN = ['migrations'];

    public function test_migration_tiap_module_hanya_membuat_tabel_berawalan_miliknya(): void
    {
        $modules = $this->modules();
        $this->assertNotEmpty($modules, 'Tidak ada module yang ditemukan; penjaga ini akan lulus tanpa menguji apa pun.');

        $inspector = new TableOwnershipInspector;
        $connection = DB::connection();

        foreach ($modules as $module) {
            $sebelum = $inspector->tabelSaatIni($connection);

            Artisan::call('migrate', [
                '--path' => $module['migrations'],
                '--realpath' => true,
                '--force' => true,
            ]);

            $sesudah = $inspector->tabelSaatIni($connection);
            $pelanggaran = $inspector->pelanggaran($sebelum, $sesudah, $module['awalan'], self::PENGECUALIAN);

            $this->assertSame([], $pelanggaran, sprintf(
                'Migration module "%s" membuat tabel yang bukan miliknya: %s. Awalan yang sah: "%s".',
                $module['id'],
                implode(', ', $pelanggaran),
                $module['awalan'],
            ));

            $this->assertNotSame(
                $sebelum,
                $sesudah,
                sprintf('Migration module "%s" tidak membuat tabel apa pun, jadi penjaga ini tidak menguji apa pun.', $module['id']),
            );
        }
    }

    public function test_pemeriksanya_menyebut_nama_tabel_yang_melanggar(): void
    {
        $pelanggaran = (new TableOwnershipInspector)->pelanggaran(
            sebelum: ['core_tenants'],
            sesudah: ['core_tenants', 'contoh_a_m_barang', 'core_module_installations', 'contoh_b_m_rak'],
            awalan: 'contoh_a_',
            pengecualian: self::PENGECUALIAN,
        );

        $this->assertSame(['contoh_b_m_rak', 'core_module_installations'], $pelanggaran);
    }

    /**
     * Semua module di bawah `modules/<publisher>/<module>/`.
     *
     * Pemindaian ini sengaja sederhana dan hidup di dalam test. Registry module yang
     * sebenarnya dibuat pada fase 2; saat itu, pemindaian di sini diganti dengannya.
     *
     * @return list<array{id: string, awalan: string, migrations: string}>
     */
    private function modules(): array
    {
        $akar = dirname(base_path(), 2).'/modules';
        $modules = [];

        foreach (glob($akar.'/*/*/app.yaml') ?: [] as $manifest) {
            /** @var array<string, mixed> $isi */
            $isi = Yaml::parseFile($manifest);
            $folder = dirname($manifest);
            $migrations = $folder.'/database/migrations';

            if (! is_dir($migrations)) {
                continue;
            }

            $awalan = (string) ($isi['table_prefix'] ?? '');
            $this->assertNotSame('', $awalan, sprintf('Manifest %s tidak menyatakan table_prefix.', $manifest));

            $modules[] = [
                'id' => (string) ($isi['id'] ?? basename($folder)),
                'awalan' => $awalan,
                'migrations' => $migrations,
            ];
        }

        return $modules;
    }
}
