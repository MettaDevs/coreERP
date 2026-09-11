<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tidak boleh ada berkas yang hidup lagi di `apps/control-plane` setelah aplikasinya dipindah
 * menjadi `apps/core`.
 *
 * Penjaga ini ada karena kegagalannya **tidak menghasilkan konflik**. Git hanya melaporkan
 * konflik untuk berkas yang diubah di dua sisi; berkas yang **ditambahkan** tidak pernah
 * bertabrakan dengan folder yang dihapus. Jadi sebuah cabang yang dibuat sebelum pemindahan,
 * lalu di-merge sesudahnya, akan menaruh berkas barunya di `apps/control-plane/` — folder yang
 * sudah tidak ada — dan merge-nya hijau, tanpa satu pun peringatan.
 *
 * Akibatnya repo diam-diam punya dua folder aplikasi: satu yang dijalankan semua orang, dan
 * satu lagi yang tidak pernah dimuat autoloader, tidak pernah ikut image edisi, dan tidak
 * pernah dijalankan test mana pun. Kode di dalamnya terlihat ada, terbaca di editor, dan tidak
 * pernah berjalan.
 *
 * Tidak ada pemeriksaan lain yang menangkapnya: PHPStan hanya menganalisa jalur yang terdaftar,
 * pemeriksa kebocoran edisi hanya melihat `modules/`, dan penjaga namespace hanya membaca berkas
 * yang memang dipindainya.
 *
 * **Yang diperiksa isinya, bukan keberadaan foldernya.** Windows menolak menghapus direktori
 * yang masih dipegang proses lain — editor atau kolam PHP yang menunjuk ke sana — sehingga
 * kerangka kosong bisa tertinggal di mesin pengembang berjam-jam setelah isinya pindah. Kerangka
 * kosong itu tidak berbahaya; berkas di dalamnya yang berbahaya. Menjaga yang kedua membuat
 * penjaga ini berarti tanpa menjadi merah palsu.
 */
class FolderLamaTidakHidupLagiTest extends TestCase
{
    public function test_tidak_ada_berkas_tertinggal_di_apps_control_plane(): void
    {
        // tests/Feature/Boundary -> tests -> core -> apps -> akar repo
        $lama = dirname(__DIR__, 5).'/apps/control-plane';

        if (! is_dir($lama)) {
            $this->addToAssertionCount(1);

            return;
        }

        $berkas = [];
        $isi = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($lama, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($isi as $entri) {
            $berkas[] = substr($entri->getPathname(), strlen($lama) + 1);
            if (count($berkas) >= 10) {
                break;
            }
        }

        $this->assertSame([], $berkas, implode("\n", [
            'Ada berkas yang hidup lagi di `apps/control-plane`, folder yang sudah dipindah ke `apps/core`.',
            '',
            'Ini hampir pasti bukan disengaja: sebuah cabang yang dibuat sebelum pemindahan di-merge',
            'tanpa konflik, dan berkas barunya mendarat di path lama. Ia tidak dimuat autoloader, tidak',
            'ikut image edisi, dan tidak pernah dijalankan test mana pun.',
            '',
            'Yang harus dilakukan: pindahkan tiap berkas ke path yang setara di bawah `apps/core/`, lalu',
            'hapus foldernya. Jangan menghapus begitu saja — isinya kode yang seseorang tulis dan kira',
            'sudah berjalan.',
        ]));
    }
}
