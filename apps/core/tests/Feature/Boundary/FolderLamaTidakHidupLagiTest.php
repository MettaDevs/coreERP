<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tidak boleh ada berkas yang hidup lagi di folder yang sudah dipindah.
 *
 * Penjaga ini lahir dari satu pemindahan — `apps/control-plane` menjadi `apps/core` — tetapi
 * yang dijaganya bukan pemindahan itu. Ia menjaga bentuk kegagalannya, dan bentuk itu berulang
 * pada setiap pemindahan berikutnya. Karena itu daftarnya ada di `folderYangSudahDipindah()`:
 * pemindahan berikutnya cukup menambah satu baris di sana.
 *
 * Penjaga ini ada karena kegagalannya **tidak menghasilkan konflik**. Git hanya melaporkan
 * konflik untuk berkas yang diubah di dua sisi; berkas yang **ditambahkan** tidak pernah
 * bertabrakan dengan folder yang dihapus. Jadi sebuah cabang yang dibuat sebelum pemindahan,
 * lalu di-merge sesudahnya, akan menaruh berkas barunya di path lama — folder yang sudah tidak
 * ada — dan merge-nya hijau, tanpa satu pun peringatan.
 *
 * Akibatnya repo diam-diam punya dua folder aplikasi: satu yang dijalankan semua orang, dan
 * satu lagi yang tidak pernah dimuat autoloader, tidak pernah ikut image edisi, dan tidak
 * pernah dijalankan test mana pun. Kode di dalamnya terlihat ada, terbaca di editor, dan tidak
 * pernah berjalan.
 *
 * Tidak ada pemeriksaan lain yang menangkapnya: PHPStan hanya menganalisa jalur yang terdaftar,
 * penjaga namespace hanya membaca berkas yang memang dipindainya, dan pemeriksa kebocoran edisi
 * menghitung folder aplikasi **di dalam image**, bukan di dalam repo — folder lama yang hidup
 * lagi tidak pernah tersalin ke sana, jadi justru tidak pernah terlihat olehnya.
 *
 * **Yang diperiksa isinya, bukan keberadaan foldernya.** Windows menolak menghapus direktori
 * yang masih dipegang proses lain — editor atau kolam PHP yang menunjuk ke sana — sehingga
 * kerangka kosong bisa tertinggal di mesin pengembang berjam-jam setelah isinya pindah. Kerangka
 * kosong itu tidak berbahaya; berkas di dalamnya yang berbahaya. Menjaga yang kedua membuat
 * penjaga ini berarti tanpa menjadi merah palsu.
 */
class FolderLamaTidakHidupLagiTest extends TestCase
{
    /**
     * Folder yang sudah dipindah, beserta tempat isinya sekarang.
     *
     * Pasangan kedua bukan hiasan: pesan galat yang hanya berkata "folder ini tidak boleh berisi
     * apa pun" memaksa pembacanya menebak ke mana isinya harus dipindahkan, dan tebakan itu
     * berakhir dengan berkas yang dihapus alih-alih dipindah.
     *
     * @return array<string, array{string, string}>
     */
    public static function folderYangSudahDipindah(): array
    {
        return [
            'apps/control-plane' => ['apps/control-plane', 'apps/core'],
        ];
    }

    #[DataProvider('folderYangSudahDipindah')]
    public function test_tidak_ada_berkas_yang_hidup_lagi(string $lama, string $baru): void
    {
        // tests/Feature/Boundary -> tests -> core -> apps -> akar repo
        $folder = dirname(__DIR__, 5).'/'.$lama;

        if (! is_dir($folder)) {
            $this->addToAssertionCount(1);

            return;
        }

        $berkas = [];
        $isi = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($isi as $entri) {
            $berkas[] = substr($entri->getPathname(), strlen($folder) + 1);
            if (count($berkas) >= 10) {
                break;
            }
        }

        $this->assertSame([], $berkas, implode("\n", [
            sprintf('Ada berkas yang hidup lagi di `%s`, folder yang sudah dipindah ke `%s`.', $lama, $baru),
            '',
            'Ini hampir pasti bukan disengaja: sebuah cabang yang dibuat sebelum pemindahan di-merge',
            'tanpa konflik, dan berkas barunya mendarat di path lama. Ia tidak dimuat autoloader, tidak',
            'ikut image edisi, dan tidak pernah dijalankan test mana pun.',
            '',
            sprintf('Yang harus dilakukan: pindahkan tiap berkas ke path yang setara di bawah `%s/`, lalu', $baru),
            'hapus foldernya. Jangan menghapus begitu saja — isinya kode yang seseorang tulis dan kira',
            'sudah berjalan.',
        ]));
    }
}
