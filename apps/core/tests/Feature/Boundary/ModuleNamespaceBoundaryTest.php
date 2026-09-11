<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use App\Support\Modules\ModulSedangDipindah;
use PHPUnit\Framework\TestCase;

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
 * Pemindaiannya sendiri hidup di `PemindaiModul`, karena pemeriksaan basi pada
 * `ModulSedangDipindahTest` harus memakai aturan yang persis sama.
 *
 * Modul yang sedang dipindah masuk dan belum dibentuk ulang dilewati di sini dan diperiksa
 * di sana; daftarnya, alasannya, dan tenggatnya ada di `ModulSedangDipindah`.
 *
 * Test ini tidak menyentuh database dan tidak memuat Laravel, jadi ia memakai TestCase
 * polos PHPUnit.
 */
class ModuleNamespaceBoundaryTest extends TestCase
{
    public function test_module_tidak_menyebut_namespace_module_lain(): void
    {
        $pemindai = PemindaiModul::padaRepo();
        $dipindah = ModulSedangDipindah::bawaan();

        $pelanggaran = [];
        $berkasDiperiksa = 0;
        $moduleDiperiksa = 0;

        foreach ($pemindai->folderModul() as $nama => $folder) {
            if ($dipindah->menandai($nama)) {
                continue;
            }

            $moduleDiperiksa++;
            $berkasDiperiksa += count($pemindai->berkasPhp($folder));
            $pelanggaran = array_merge($pelanggaran, $pemindai->pelanggaranNamespace($folder));
        }

        $this->assertGreaterThan(0, $moduleDiperiksa, 'Tidak ada module yang diperiksa; penjaga ini akan lulus tanpa menguji apa pun.');
        $this->assertGreaterThan(0, $berkasDiperiksa, 'Tidak ada berkas PHP yang dibaca; penjaga ini tidak menguji apa pun.');
        $this->assertSame([], array_values(array_unique($pelanggaran)), implode("\n", [
            'Ada module yang menyebut module lain. Module yang saling memanggil tidak bisa dicabut sendirian.',
            'Yang boleh disebut module: kelas Core (App\\), kerangka kerja, dan kelasnya sendiri.',
        ]));
    }

    public function test_module_hanya_menyentuh_kelas_core_yang_dikontrakkan(): void
    {
        $pemindai = PemindaiModul::padaRepo();
        $dipindah = ModulSedangDipindah::bawaan();

        $pelanggaran = [];
        $moduleDiperiksa = 0;

        foreach ($pemindai->folderModul() as $nama => $folder) {
            if ($dipindah->menandai($nama)) {
                continue;
            }

            $moduleDiperiksa++;
            $pelanggaran = array_merge($pelanggaran, $pemindai->pelanggaranKelasCore($folder));
        }

        $this->assertGreaterThan(0, $moduleDiperiksa, 'Tidak ada module yang diperiksa; penjaga ini akan lulus tanpa menguji apa pun.');
        $this->assertSame([], array_values(array_unique($pelanggaran)), implode("\n", [
            'Module menyentuh kelas Core di luar kontrak.',
            'Satu-satunya permukaan yang boleh disebut module adalah App\\Support\\Modules\\Contracts.',
            'Butuh sesuatu yang belum ada di sana? Usulkan antarmuka baru; jangan mengambil jalan',
            'pintas ke kelas Core, karena kelas Core bebas berubah bentuk dan module akan ikut',
            'pecah tanpa peringatan.',
        ]));
    }

    public function test_pemeriksa_kelas_core_membedakan_kontrak_dari_kelas_biasa(): void
    {
        $contoh = implode("\n", [
            'use App\\Support\\Modules\\Contracts\\PenerbitNomor;',
            'use App\\Support\\Modules\\Contracts\\MilikTenant;',
            'use App\\Models\\Tenant;',
            'use App\\Support\\CurrentWorkspace;',
        ]);

        $this->assertSame(
            ['App\\Models\\Tenant', 'App\\Support\\CurrentWorkspace'],
            PemindaiModul::kelasCoreYangDisebut($contoh),
            'Hanya isi Contracts yang boleh; model Core dan kelas Support lain tidak.',
        );
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
            PemindaiModul::namespaceYangDisebut($contoh),
            'Pemeriksa harus menangkap keempat jalur: namespace sendiri, impor, pemanggilan statis, dan nama di dalam string.',
        );
    }
}
