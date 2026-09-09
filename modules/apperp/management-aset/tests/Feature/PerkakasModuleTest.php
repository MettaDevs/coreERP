<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Perkakas module: konfigurasi dan perintah artisan.
 *
 * Tiga hal yang diperiksa di sini dulu tidak dijaga apa pun, dan ketiganya rusak dengan
 * cara yang sama — diam.
 *
 * - Konfigurasi module yang duduk di akar ruang konfigurasi Core tidak membuat satu pun
 *   test merah; ia baru terasa pada module berikutnya yang kebetulan menamai berkasnya
 *   `cache.php` atau `database.php`.
 * - Perintah artisan yang tidak didaftarkan tidak melempar apa pun; ia cuma tidak ada,
 *   dan yang menemukannya adalah orang yang mengetiknya di container produksi.
 * - Perintah pembangun layout yang memakai `resource_path()` tetap berhasil dan tetap
 *   mencetak "ditulis:" — hanya saja berkas yang ditulisnya bukan berkas yang dibaca
 *   saat mencetak work order.
 *
 * Test ini tidak menyentuh database, tapi tetap memakai `TestCase` Core supaya penyedia
 * layanan module benar-benar dijalankan; itu justru yang sedang diuji.
 */
class PerkakasModuleTest extends TestCase
{
    public function test_konfigurasi_module_hanya_ada_di_bawah_awalan_modules(): void
    {
        $this->assertIsArray(
            config('modules.management-aset.indonesia_starter'),
            'Konfigurasi module tidak terbaca di `modules.management-aset`; penyedia layanan module tidak menggabungkannya.',
        );

        foreach (['management_aset', 'management-aset'] as $kunciAkar) {
            $this->assertNull(config($kunciAkar), sprintf(
                'Kunci konfigurasi module `%s` masih berada di akar ruang konfigurasi Core. '.
                'Akar itu milik Core; satu module yang menaruh namanya di sana memberi izin diam-diam '.
                'kepada module berikutnya untuk menimpa `database`, `cache`, atau `mail` hanya dengan '.
                'menamai berkasnya begitu.',
                $kunciAkar,
            ));
        }
    }

    public function test_perintah_artisan_module_terdaftar(): void
    {
        $terdaftar = array_keys(Artisan::all());

        foreach (['laporan:bangun-layout-bawaan', 'management-aset:seed-maintenance'] as $perintah) {
            $this->assertContains($perintah, $terdaftar, sprintf(
                'Perintah `%s` tidak terdaftar. Perintah yang tidak terdaftar tidak ada bedanya '.
                'dengan perintah yang tidak ada: ia tidak melempar apa pun, ia cuma tidak muncul.',
                $perintah,
            ));
        }
    }

    public function test_perintah_layout_menulis_ke_folder_module_bukan_folder_core(): void
    {
        $akarModule = dirname(__DIR__, 2);
        $berkas = [
            $akarModule.'/resources/laporan/work-order/standar.docx',
            $akarModule.'/resources/laporan/daftar-work-order/standar.xlsx',
        ];

        // Layout bawaan ikut di-commit, jadi isinya dikembalikan apa adanya setelah perintah
        // menimpanya. Yang diuji adalah ke mana ia menulis, bukan apa yang ditulisnya.
        $cadangan = [];
        foreach ($berkas as $jalur) {
            $this->assertFileExists($jalur);
            $cadangan[$jalur] = (string) file_get_contents($jalur);
        }

        try {
            $this->assertSame(0, Artisan::call('laporan:bangun-layout-bawaan'));

            foreach ($berkas as $jalur) {
                $this->assertNotSame('', (string) file_get_contents($jalur));
            }

            $this->assertDirectoryDoesNotExist(resource_path('laporan'), sprintf(
                'Perintah menulis layout ke `%s`, yaitu folder resources milik Core. '.
                'Di dalam satu runtime `resource_path()` bukan lagi folder module, dan berkas '.
                'yang dibaca saat mencetak tetap yang ada di folder module.',
                resource_path('laporan'),
            ));
        } finally {
            foreach ($cadangan as $jalur => $isi) {
                file_put_contents($jalur, $isi);
            }

            // Kalau perintah kembali memakai `resource_path()`, ia meninggalkan folder di
            // dalam repo Core. Dibersihkan di sini supaya kegagalannya berbunyi sekali,
            // bukan berubah menjadi berkas tak bertuan yang ikut ter-commit.
            $this->hapusFolder(resource_path('laporan'));
        }
    }

    private function hapusFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        foreach ((array) scandir($folder) as $entri) {
            if ($entri === '.' || $entri === '..') {
                continue;
            }

            $jalur = $folder.'/'.$entri;
            is_dir($jalur) ? $this->hapusFolder($jalur) : unlink($jalur);
        }

        rmdir($folder);
    }
}
