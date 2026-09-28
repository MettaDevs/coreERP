<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use LogicException;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
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

        foreach (['management-aset:build-builtin-layouts', 'management-aset:seed-maintenance'] as $perintah) {
            $this->assertContains($perintah, $terdaftar, sprintf(
                'Perintah `%s` tidak terdaftar. Perintah yang tidak terdaftar tidak ada bedanya '.
                'dengan perintah yang tidak ada: ia tidak melempar apa pun, ia cuma tidak muncul.',
                $perintah,
            ));
        }
    }

    public function test_perintah_layout_menulis_ke_folder_module_bukan_folder_core(): void
    {
        // Layout bawaan ikut di-commit, jadi isinya dikembalikan apa adanya setelah perintah
        // menimpanya. Yang diuji adalah ke mana ia menulis, bukan apa yang ditulisnya.
        //
        // Yang dicadangkan seluruh layout bawaan, bukan hanya dua yang diperiksa: perintah
        // menimpa semuanya, dan yang tidak dikembalikan tertinggal sebagai berkas berubah di
        // working tree setiap kali suite ini berjalan.
        $berkas = glob(dirname(__DIR__, 2).'/resources/laporan/*/*.*') ?: [];
        $this->assertNotSame([], $berkas);
        $cadangan = [];
        foreach ($berkas as $jalur) {
            $cadangan[$jalur] = (string) file_get_contents($jalur);
        }

        try {
            $this->assertSame(0, Artisan::call('management-aset:build-builtin-layouts'), Artisan::output());

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

    public function test_layout_command_rejects_report_without_layout_builder(): void
    {
        // Menambah laporan tidak lagi berarti menyunting perintah pembangunnya; pembangunnya
        // ditemukan dari folder. Yang menggantikan langkah itu adalah penolakan ini: laporan
        // yang menyatakan layout bawaan tanpa pembangun tidak boleh lolos diam-diam, karena
        // berkasnya tidak akan pernah dibangun dan tombol Cetak-nya gagal di tangan pengguna.
        app(ReportRegistry::class)->register(new class implements ReportDefinition
        {
            public function code(): string
            {
                return 'laporan-tanpa-pembangun';
            }

            public function name(): string
            {
                return 'Laporan tanpa pembangun';
            }

            public function description(): string
            {
                return '';
            }

            public function permission(): string
            {
                return 'management-aset.aset.read';
            }

            public function builtinLayouts(): array
            {
                return [new BuiltinLayout('standar', 'Standar', '', 'xlsx')];
            }

            public function parameterRules(): array
            {
                return [];
            }

            public function fields(): array
            {
                return [];
            }

            public function data(ReportContext $context, array $parameters): ReportData
            {
                throw new LogicException('Tidak dipanggil oleh perintah pembangun layout.');
            }
        });

        $this->assertSame(1, Artisan::call('management-aset:build-builtin-layouts'));
        $this->assertStringContainsString('laporan-tanpa-pembangun', Artisan::output());
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
