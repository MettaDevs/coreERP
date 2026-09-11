<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use PHPUnit\Framework\TestCase;

/**
 * Setiap kelas module benar-benar bisa dimuat dengan nama yang dijanjikan `composer.json`-nya.
 *
 * Ini penjaga atas pekerjaan yang paling mudah salah tanpa ketahuan: penggantian namespace
 * massal. Satu berkas yang terlewat, satu huruf besar-kecil yang tidak cocok dengan nama
 * foldernya, atau satu pemetaan PSR-4 yang salah tulis tidak menghasilkan kesalahan apa pun
 * sampai ada yang benar-benar memanggil kelas itu — dan untuk module yang belum punya test
 * berjalan, itu bisa berbulan-bulan kemudian.
 *
 * Analisa statis tidak menutup lubang ini: module yang sedang dipindah justru dikecualikan dari
 * PHPStan, jadi kriteria "analisa statis lulus" hijau tanpa memeriksa satu berkas pun milik
 * module itu.
 *
 * Yang diperiksa: untuk tiap pemetaan PSR-4 pada `composer.json` module, tiap berkas PHP di
 * bawah foldernya wajib mendeklarasikan nama yang sesuai jalurnya, dan nama itu wajib bisa
 * dimuat autoloader.
 */
class ModuleAutoloadTest extends TestCase
{
    public function test_tiap_kelas_module_bisa_dimuat_dengan_nama_yang_dijanjikan(): void
    {
        $berkasDiperiksa = 0;
        $pelanggaran = [];

        foreach ($this->modulDenganPsr4() as $folder => $pemetaan) {
            foreach ($pemetaan as $awalan => $anak) {
                $akar = $folder.'/'.rtrim($anak, '/');

                if (! is_dir($akar)) {
                    $pelanggaran[] = sprintf('%s: pemetaan PSR-4 "%s" menunjuk folder yang tidak ada (%s)', basename($folder), $awalan, $anak);

                    continue;
                }

                foreach ($this->berkasPhp($akar) as $berkas) {
                    $berkasDiperiksa++;
                    $relatif = substr($berkas, strlen($akar) + 1);
                    $harusnya = $awalan.str_replace('/', '\\', substr($relatif, 0, -4));

                    // Namespace dibaca dari berkasnya lebih dulu, bukan langsung dicoba dimuat.
                    // Berkas yang namespace-nya salah bisa saja mendeklarasikan nama yang **sudah
                    // dipakai Core** — memuatnya membuat PHP fatal dan mematikan proses test, jadi
                    // kegagalannya muncul sebagai "Premature end of PHP process" tanpa menyebut
                    // berkas mana yang salah. Itu pernah terjadi saat penjaga ini dibuktikan.
                    $harusnyaNamespace = substr($harusnya, 0, (int) strrpos($harusnya, '\\'));
                    $namespaceTertulis = $this->namespaceTertulis($berkas);

                    if ($namespaceTertulis !== $harusnyaNamespace) {
                        $pelanggaran[] = sprintf(
                            '%s: %s mendeklarasikan namespace %s, seharusnya %s',
                            basename($folder),
                            $relatif,
                            $namespaceTertulis === null ? '(tidak ada)' : $namespaceTertulis,
                            $harusnyaNamespace,
                        );

                        continue;
                    }

                    if (! $this->adaSebagaiTipe($harusnya)) {
                        $pelanggaran[] = sprintf('%s: %s tidak dapat dimuat sebagai %s', basename($folder), $relatif, $harusnya);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $berkasDiperiksa, 'Tidak satu pun berkas module terbaca; pemindaiannya salah alamat.');

        $this->assertSame([], $pelanggaran, sprintf(
            "Kelas module tidak bisa dimuat dengan nama yang dijanjikan composer.json-nya:\n- %s\n".
            'Penyebab yang paling sering: satu berkas terlewat saat penggantian namespace massal, '.
            'nama folder yang huruf besar-kecilnya tidak cocok dengan namespace-nya, atau pemetaan '.
            'PSR-4 yang salah tulis. Tidak ada satu pun dari itu yang gagal dengan sendirinya sampai '.
            'ada yang memanggil kelasnya.',
            implode("\n- ", $pelanggaran),
        ));
    }

    private function namespaceTertulis(string $berkas): ?string
    {
        $isi = (string) file_get_contents($berkas);

        if (preg_match('/^namespace\s+([^;]+);/m', $isi, $cocok) !== 1) {
            return null;
        }

        return trim($cocok[1]);
    }

    private function adaSebagaiTipe(string $nama): bool
    {
        return class_exists($nama) || interface_exists($nama) || trait_exists($nama) || enum_exists($nama);
    }

    /**
     * Folder module dipetakan ke pemetaan PSR-4 pada `composer.json`-nya.
     *
     * `autoload-dev` sengaja tidak ikut: ia tidak dimuat pada pemasangan produksi, dan namespace
     * test module diurus task tersendiri.
     *
     * @return array<string, array<string, string>>
     */
    private function modulDenganPsr4(): array
    {
        $berkas = glob(dirname(__DIR__, 5).'/modules/*/*/composer.json');
        $berkas = $berkas === false ? [] : $berkas;

        $this->assertNotSame([], $berkas, 'Tidak ada composer.json module yang terbaca; pemindaiannya salah alamat.');

        $hasil = [];

        foreach ($berkas as $b) {
            /** @var array<string, mixed> $isi */
            $isi = json_decode((string) file_get_contents($b), true, 512, JSON_THROW_ON_ERROR);
            $psr4 = $isi['autoload']['psr-4'] ?? [];

            if (is_array($psr4) && $psr4 !== []) {
                /** @var array<string, string> $psr4 */
                $hasil[dirname($b)] = $psr4;
            }
        }

        return $hasil;
    }

    /** @return list<string> */
    private function berkasPhp(string $akar): array
    {
        $ditemukan = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $berkas */
        foreach ($iterator as $berkas) {
            if ($berkas->isFile() && $berkas->getExtension() === 'php') {
                $ditemukan[] = str_replace('\\', '/', $berkas->getPathname());
            }
        }

        sort($ditemukan);

        return $ditemukan;
    }
}
