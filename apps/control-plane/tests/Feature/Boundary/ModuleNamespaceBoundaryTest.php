<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Penjaga kedua: kode sebuah module tidak boleh menyebut namespace module lain.
 *
 * Batas tabel saja tidak cukup. Dua module bisa punya tabel yang rapi terpisah dan tetap
 * saling memanggil lewat PHP, dan begitu itu terjadi module-nya tidak bisa lagi dicabut
 * sendirian: mencabut satu membuat yang lain gagal memuat kelas.
 *
 * Pemeriksaannya membaca berkas, bukan menganalisa tipe. Itu pilihan sadar. Aturan PHPStan
 * sempat ditulis lebih dulu dan dibuang karena berlubang: PHPStan hanya mengunjungi nama
 * kelas pada posisi tertentu — pada berkas contoh hanya tiga, semuanya tipe argumen —
 * sehingga baris `use` dan pemanggilan statis lolos begitu saja. Membaca berkas menangkap
 * semuanya, termasuk nama kelas di dalam string dan docblock, yang justru jalur yang paling
 * mudah dipakai untuk menembus batas.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase
 * polos PHPUnit.
 */
class ModuleNamespaceBoundaryTest extends TestCase
{
    public function test_module_tidak_menyebut_namespace_module_lain(): void
    {
        $modules = $this->modules();
        $this->assertNotEmpty($modules, 'Tidak ada module yang ditemukan; penjaga ini akan lulus tanpa menguji apa pun.');

        $pelanggaran = [];
        $berkasDiperiksa = 0;

        foreach ($modules as $namespace => $folder) {
            foreach ($this->berkasPhp($folder) as $berkas) {
                $berkasDiperiksa++;
                $isi = (string) file_get_contents($berkas->getPathname());

                foreach ($this->namespaceYangDisebut($isi) as $disebut) {
                    if ($disebut !== $namespace) {
                        $pelanggaran[] = sprintf(
                            '%s menyebut %s',
                            $this->jalurRingkas($berkas->getPathname()),
                            $disebut,
                        );
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $berkasDiperiksa, 'Tidak ada berkas PHP yang dibaca; penjaga ini tidak menguji apa pun.');
        $this->assertSame([], array_values(array_unique($pelanggaran)), implode("\n", [
            'Ada module yang menyebut module lain. Module yang saling memanggil tidak bisa dicabut sendirian.',
            'Yang boleh disebut module: kelas Core (App\\), kerangka kerja, dan kelasnya sendiri.',
        ]));
    }

    public function test_pemeriksanya_menangkap_impor_pemanggilan_statis_dan_string(): void
    {
        $contoh = <<<'PHP'
        <?php
        namespace Modules\Apperp\ContohA\Http;
        use Modules\Apperp\ContohB\Models\Rak;
        class X {
            public function y(): void {
                \Modules\Apperp\ContohC\Support\Alat::jalan();
                app('Modules\\Apperp\\ContohD\\Kontrak\\Mesin');
            }
        }
        PHP;

        $this->assertSame(
            ['Apperp\ContohA', 'Apperp\ContohB', 'Apperp\ContohC', 'Apperp\ContohD'],
            $this->namespaceYangDisebut($contoh),
            'Pemeriksa harus menangkap keempat jalur: namespace sendiri, impor, pemanggilan statis, dan nama di dalam string.',
        );
    }

    /**
     * Nama module yang disebut sebuah isi berkas, misalnya `Apperp\ContohA`.
     *
     * Garis miring ganda ikut dicocokkan karena nama kelas di dalam string PHP ditulis
     * dengan garis miring ganda.
     *
     * @return list<string>
     */
    private function namespaceYangDisebut(string $isi): array
    {
        preg_match_all('/Modules\\\\{1,2}([A-Za-z0-9_]+)\\\\{1,2}([A-Za-z0-9_]+)/', $isi, $cocok, PREG_SET_ORDER);

        $hasil = array_map(
            static fn (array $bagian): string => $bagian[1].'\\'.$bagian[2],
            $cocok,
        );

        $hasil = array_values(array_unique($hasil));
        sort($hasil);

        return $hasil;
    }

    /**
     * Nama module dipetakan ke foldernya, misalnya `Apperp\ContohA` ke folder module itu.
     *
     * @return array<string, string>
     */
    private function modules(): array
    {
        // tests/Feature/Boundary -> tests -> control-plane -> apps -> akar repo
        $akar = dirname(__DIR__, 5).'/modules';
        $modules = [];

        foreach (glob($akar.'/*/*/composer.json') ?: [] as $manifest) {
            $folder = dirname($manifest);
            $publisher = $this->studly(basename(dirname($folder)));
            $module = $this->studly(basename($folder));
            $modules[$publisher.'\\'.$module] = $folder;
        }

        return $modules;
    }

    /** @return list<SplFileInfo> */
    private function berkasPhp(string $folder): array
    {
        $berkas = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $berkas[] = $item;
            }
        }

        return $berkas;
    }

    private function studly(string $nama): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $nama)));
    }

    private function jalurRingkas(string $jalur): string
    {
        $jalur = str_replace('\\', '/', $jalur);
        $potong = strpos($jalur, '/modules/');

        return $potong === false ? $jalur : substr($jalur, $potong + 1);
    }
}
