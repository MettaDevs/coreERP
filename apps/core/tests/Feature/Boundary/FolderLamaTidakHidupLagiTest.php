<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Kode Core tidak boleh mendarat di folder aplikasi lain.
 *
 * Penjaga ini lahir dari satu pemindahan — `apps/control-plane` menjadi `apps/core` — dan bentuk
 * yang dijaganya ini: kegagalannya **tidak menghasilkan konflik**. Git hanya melaporkan konflik
 * untuk berkas yang diubah di dua sisi; berkas yang **ditambahkan** tidak pernah bertabrakan
 * dengan folder yang dihapus. Sebuah cabang yang dibuat sebelum pemindahan, lalu di-merge
 * sesudahnya, menaruh berkas barunya di path lama — dan merge-nya hijau, tanpa satu pun
 * peringatan.
 *
 * Akibatnya repo diam-diam punya kode yang tidak pernah dimuat autoloader, tidak pernah ikut image
 * edisi, dan tidak pernah dijalankan test mana pun. Ia terlihat ada, terbaca di editor, dan tidak
 * pernah berjalan.
 *
 * ## Kenapa bentuknya berubah pada 12 September 2026
 *
 * Versi pertama penjaga ini menuntut folder `apps/control-plane` **kosong**, karena isinya sudah
 * pindah ke `apps/core`. Nama itu kemudian **dipakai ulang** — konsol operator vendor menempatinya,
 * dan kali ini namanya memang benar: isinya control plane sungguhan, bukan runtime Core yang salah
 * nama.
 *
 * Sejak saat itu tuntutan "folder ini harus kosong" menjadi merah selamanya tanpa ada yang salah.
 * Membuangnya begitu saja akan melepas risikonya sekaligus; yang dilakukan di sini adalah
 * **mempersempit** tuntutannya menjadi yang masih benar: folder itu boleh berisi kode konsol, dan
 * tidak boleh berisi kode Core.
 *
 * Yang membedakan keduanya namespace — konsol memakai `ControlPlane\`, Core memakai `App\` — dan
 * itu tanda yang tidak dapat dipalsukan tanpa sengaja: berkas Core yang mendarat di sana akan
 * membawa `namespace App;` apa adanya.
 *
 * Mekanisme lama tidak dihapus dari ingatan: pemindahan folder berikutnya yang **tidak** dipakai
 * ulang namanya cukup menirukan versi pertama penjaga ini dari riwayat git — menuntut foldernya
 * kosong, dan menyebutkan ke mana isinya pindah.
 */
class FolderLamaTidakHidupLagiTest extends TestCase
{
    /** Folder aplikasi lain di repo ini, beserta namespace yang sah di dalamnya. */
    private const KONSOL = 'apps/control-plane';

    public function test_berkas_milik_core_tidak_mendarat_di_konsol(): void
    {
        // tests/Feature/Boundary -> tests -> core -> apps -> akar repo
        $folder = dirname(__DIR__, 5).'/'.self::KONSOL;

        $this->assertDirectoryExists(
            $folder,
            'Folder konsol tidak ada. Penjaga ini tidak dapat membuktikan apa pun tanpa subjek — '
            .'kalau konsolnya memang dipindah lagi, perbarui penjaga ini bersamaan.'
        );

        $tersangka = [];
        $diperiksa = 0;

        foreach ($this->berkasPhp($folder) as $jalur) {
            $diperiksa++;
            $isi = (string) file_get_contents($jalur);

            // Dicocokkan pada deklarasi namespace-nya, bukan pada kemunculan kata `App` di mana
            // pun: konsol memang menyebut kelas Core di dalam komentar dan pesan galat, dan
            // pencocokan yang lebih longgar akan merah karena prosa.
            if (preg_match('/^\s*namespace\s+App\s*[;\\\\]/m', $isi) === 1) {
                $tersangka[] = substr($jalur, strlen($folder) + 1);
            }
        }

        $this->assertGreaterThan(
            10,
            $diperiksa,
            'Hanya '.$diperiksa.' berkas PHP yang terbaca di konsol. Pemindaian ini hampir pasti '
            .'salah alamat, dan pemeriksa yang tidak menemukan subjek tidak dapat dibedakan dari '
            .'pemeriksa yang tidak menemukan pelanggaran.'
        );

        $this->assertSame([], $tersangka, implode("\n", [
            'Ada berkas bernamespace `App\\` di dalam `'.self::KONSOL.'`, folder milik konsol operator.',
            '',
            'Ini hampir pasti bukan disengaja: sebuah cabang yang dibuat sebelum `apps/control-plane`',
            'menjadi `apps/core` di-merge tanpa konflik, dan berkas Core-nya mendarat di path lama —',
            'yang kini ditempati aplikasi lain. Ia tidak dimuat autoloader mana pun, tidak ikut image',
            'edisi, dan tidak pernah dijalankan test mana pun.',
            '',
            'Yang harus dilakukan: pindahkan tiap berkas ke path yang setara di bawah `apps/core/`.',
            'Jangan menghapus begitu saja — isinya kode yang seseorang tulis dan kira sudah berjalan.',
        ]));
    }

    /** @return iterable<string> */
    private function berkasPhp(string $folder): iterable
    {
        $isi = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($isi as $entri) {
            $jalur = str_replace('\\', '/', $entri->getPathname());

            // `vendor/` dan `node_modules/` memang penuh namespace orang lain, dan `storage/`
            // memuat view Blade terkompilasi yang menyalin jalur berkas aslinya.
            if (str_contains($jalur, '/vendor/') || str_contains($jalur, '/node_modules/') || str_contains($jalur, '/storage/')) {
                continue;
            }

            if (str_ends_with($jalur, '.php')) {
                yield $jalur;
            }
        }
    }
}
