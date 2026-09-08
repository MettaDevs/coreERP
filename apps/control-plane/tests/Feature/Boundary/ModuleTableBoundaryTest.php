<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\TableOwnershipInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penjaga pertama: migration sebuah module hanya boleh membuat tabel berawalan miliknya.
 *
 * Batas ini **tidak** ditegakkan mesin database. Semua tabel berada di satu database dan
 * satu koneksi, jadi tidak ada yang menghalangi migration module membuat tabel bernama
 * `core_tenants`. Yang menegakkannya adalah test ini. Ia harus disebut apa adanya supaya
 * tidak ada yang merasa aman tanpa alasan.
 *
 * ## Asimetri yang diterima, bukan disamarkan
 *
 * Dua penjaga lain hanya **membaca** berkas, jadi modul yang sedang dipindah tetap bisa
 * dipindai penuh dan pemeriksaan basi bisa dihitung untuknya. Penjaga ini **menjalankan**
 * migration modul. Modul yang belum dibentuk ulang masih membawa migration kerangka Laravel
 * bawaan repo asalnya — `users`, `jobs`, `cache` — dan menjalankannya di schema test berarti
 * bertabrakan dengan tabel milik Core yang bernama sama. Bukan sekadar melaporkan pelanggaran:
 * migration-nya gagal, atau lebih buruk, berhasil menimpa.
 *
 * Karena itu, untuk penjaga ini pengecualian harus **melewatkan penjalanannya sama sekali**.
 * Konsekuensinya jujur dan harus ditulis di sini supaya tidak ada yang menyangka pengecualian
 * bekerja seragam pada ketiga penjaga: untuk dimensi awalan tabel, pemeriksaan basi tidak bisa
 * dihitung, dan `tenggat` pada `ModulSedangDipindah` menjadi satu-satunya yang mengakhirinya.
 * Bila entri dibuang sebelum awalan tabelnya benar-benar dibereskan, yang memberi tahu adalah
 * penjaga ini pada pull request berikutnya, bukan pemeriksaan basi.
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
        // Modul yang sedang dipindah tidak ikut dijalankan; alasannya ada pada docblock kelas ini.
        // Daftarnya tidak ditulis ulang di sini melainkan dibaca dari ModulSedangDipindah, satu
        // tempat yang sama dengan dua penjaga lain. Daftar batas modul yang hidup di tiga tempat
        // akan menyimpang, dan yang menyimpang lebih berbahaya daripada yang tidak ada.
        $modules = PemindaiModul::padaRepo()->modulDenganMigration(ModulSedangDipindah::bawaan());
        $this->assertNotEmpty($modules, 'Tidak ada module yang dijalankan; penjaga ini akan lulus tanpa menguji apa pun.');

        $inspector = new TableOwnershipInspector;
        $connection = DB::connection();

        foreach ($modules as $module) {
            $this->assertNotSame('', $module['awalan'], sprintf(
                'Module "%s" tidak menyatakan table_prefix pada app.yaml, jadi tidak ada awalan yang bisa ditegakkan.',
                $module['id'],
            ));

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
}
