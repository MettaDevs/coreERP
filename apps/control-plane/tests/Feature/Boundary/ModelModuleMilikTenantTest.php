<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use PHPUnit\Framework\TestCase;

/**
 * Setiap model Eloquent milik module memakai `MilikTenant`.
 *
 * Ini penjaga yang menjawab pertanyaan paling mahal pada penempatan gabungan: satu model yang
 * lupa disaring membocorkan data seluruh pelanggan, dan kebocoran itu tidak pernah gagal dengan
 * sendirinya — ia tampak seperti daftar yang isinya kebetulan banyak.
 *
 * Kenapa penjaga berupa pemindaian berkas, bukan mengandalkan model dasar: model dasar hanya
 * menjaga yang mewarisinya. Pada modul aset ada **tiga** model yang tidak mewarisi `MasterData`
 * — dan rencana pemindahan hanya menyebut satu. Dua sisanya justru yang memegang data aset dan
 * buku penyusutannya. Penjaga yang membaca berkas tidak bisa dikelabui pewarisan.
 *
 * Test ini berlaku untuk **semua** modul tanpa kecuali, termasuk yang sedang dipindah masuk.
 * Penyaringan tenant bukan hal yang boleh menunggu: modul yang sudah dipasang di tenant
 * sungguhan sambil menunggu dibereskan adalah modul yang sudah membocorkan data.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase polos.
 */
class ModelModuleMilikTenantTest extends TestCase
{
    public function test_tiap_model_module_memakai_milik_tenant(): void
    {
        $diperiksa = 0;
        $pelanggaran = [];

        foreach ($this->berkasModelModule() as $modul => $daftar) {
            foreach ($daftar as $berkas) {
                $isi = (string) file_get_contents($berkas);

                if (preg_match('/\bextends\s+(Model|Authenticatable|Pivot)\b/', $isi) !== 1) {
                    continue;
                }

                $diperiksa++;

                if (preg_match('/\buse\s+MilikTenant\s*;/', $isi) !== 1) {
                    $pelanggaran[] = $modul.'/'.basename($berkas);
                }
            }
        }

        $this->assertGreaterThan(0, $diperiksa, 'Tidak satu pun model module terbaca; pemindaiannya salah alamat.');

        $this->assertSame([], $pelanggaran, sprintf(
            "Model module tidak memakai MilikTenant: %s\n".
            'Model yang tidak tersaring membocorkan baris milik tenant lain pada penempatan gabungan, '.
            'dan kebocoran itu tidak gagal dengan sendirinya — ia tampak seperti daftar yang isinya '.
            'kebetulan banyak. Model dasar tidak cukup: yang tidak mewarisinya tidak ikut terjaga.',
            implode(', ', $pelanggaran),
        ));
    }

    /**
     * Berkas PHP di bawah `src/` tiap modul, dikelompokkan menurut nama modulnya.
     *
     * @return array<string, list<string>>
     */
    private function berkasModelModule(): array
    {
        $folder = glob(dirname(__DIR__, 5).'/modules/*/*', GLOB_ONLYDIR);
        $folder = $folder === false ? [] : $folder;

        $this->assertNotSame([], $folder, 'Tidak ada folder module yang terbaca; pemindaiannya salah alamat.');

        $hasil = [];

        foreach ($folder as $f) {
            if (! is_dir($f.'/src')) {
                continue;
            }

            $ditemukan = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($f.'/src', \FilesystemIterator::SKIP_DOTS));

            /** @var \SplFileInfo $berkas */
            foreach ($iterator as $berkas) {
                if ($berkas->isFile() && $berkas->getExtension() === 'php') {
                    $ditemukan[] = $berkas->getPathname();
                }
            }

            sort($ditemukan);
            $hasil[basename($f)] = $ditemukan;
        }

        return $hasil;
    }
}
