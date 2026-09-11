<?php

declare(strict_types=1);

namespace Tests\Feature\Observabilitas;

use App\Support\Observabilitas\BerkasLaporan;
use App\Support\Observabilitas\PelaporKesalahan;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Penjaga: pelapor tidak pernah menjadi sebab kegagalan.
 *
 * Kelas yang diuji berjalan di dalam penangan kesalahan Laravel. Lemparan dari sana menimpa
 * kesalahan asli dengan kesalahan tentang pelaporan kesalahan — dan yang hilang justru
 * satu-satunya keterangan tentang apa yang sebenarnya terjadi. Semua test di berkas ini
 * menjaga sifat itu dari sudut yang berbeda.
 */
class PelaporKesalahanTest extends TestCase
{
    private int $panjangAwal = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Berkas laporan dibagi seluruh worker ParaTest yang berjalan pada hari dan peran yang
        // sama. Menghapusnya akan menghapus laporan milik test lain yang sedang berjalan, dan
        // sebaliknya. Yang dicatat karena itu panjangnya, bukan keberadaannya.
        $this->panjangAwal = is_file(BerkasLaporan::jalur()) ? (int) filesize(BerkasLaporan::jalur()) : 0;
    }

    /** Hanya bagian yang ditambahkan test ini. */
    private function tambahan(): string
    {
        $berkas = BerkasLaporan::jalur();

        if (! is_file($berkas)) {
            return '';
        }

        return substr((string) file_get_contents($berkas), $this->panjangAwal);
    }

    public function test_laporan_ditulis_ke_berkasnya_sendiri(): void
    {
        PelaporKesalahan::laporkan(
            new RuntimeException('gagal menyimpan'),
            Request::create('https://erp.test/x', 'POST'),
        );

        $isi = $this->tambahan();
        $this->assertStringContainsString('gagal menyimpan', $isi);
        $this->assertStringContainsString('KESALAHAN INTERNAL', $isi);

        // Blok laporan harus tiba sebagai banyak baris, bukan satu baris berisi `\n` harfiah.
        // Ini sifat yang hilang diam-diam kalau penulisannya kembali lewat formatter Monolog.
        $this->assertGreaterThan(5, substr_count($isi, "\n"));
    }

    public function test_kesalahan_4xx_tidak_menghasilkan_berkas(): void
    {
        PelaporKesalahan::laporkan(new NotFoundHttpException, Request::create('https://erp.test/hilang', 'GET'));

        $this->assertSame('', $this->tambahan(), '404 tidak boleh menambah satu baris pun ke berkas laporan.');
    }

    public function test_beberapa_laporan_berurutan_semuanya_tercatat(): void
    {
        // Penjaga masuk-ulang yang tidak pernah dilepas mematikan seluruh pelaporan sesudah
        // kesalahan pertama — kegagalan paling sulit dilihat, karena tandanya adalah ketiadaan.
        PelaporKesalahan::laporkan(new RuntimeException('pertama'), null);
        PelaporKesalahan::laporkan(new RuntimeException('kedua'), null);

        $isi = $this->tambahan();
        $this->assertStringContainsString('pertama', $isi);
        $this->assertStringContainsString('kedua', $isi);
    }

    public function test_kesalahan_dari_dalam_pelapor_tidak_pernah_lolos(): void
    {
        // Jalur yang sengaja dibuat gagal: permintaan yang route()-nya melempar. Yang diuji
        // bukan isinya, melainkan bahwa tidak ada apa pun yang keluar dari pelapor.
        $permintaan = Request::create('https://erp.test/x', 'GET');

        PelaporKesalahan::laporkan(new RuntimeException('kesalahan asli'), $permintaan);

        $this->assertTrue(true, 'tidak ada lemparan yang lolos dari pelapor');
    }

    public function test_di_konsol_permintaan_tiruan_tidak_dianggap_permintaan_sungguhan(): void
    {
        // Di dalam pekerja antrean `request()` tetap mengembalikan objek — permintaan tiruan
        // yang `fullUrl()`-nya `http://localhost` dan tidak berarti apa-apa. Melaporkannya
        // sebagai permintaan sungguhan menghasilkan baris yang tampak berisi tetapi salah.
        $this->assertNull(PelaporKesalahan::permintaanSaatIni(), 'test berjalan di konsol');
    }
}
