<?php

declare(strict_types=1);

namespace App\Support\Modules;

use RuntimeException;

/**
 * Daftar modul yang ikut ke dalam image yang dibagikan ke klien.
 *
 * **Tidak ada lagi edisi per pelanggan.** Sampai 18 September 2026 folder `editions/` berisi satu
 * manifest per pelanggan — nama pelanggan, nomor rilis sendiri, dan daftar modul yang dibeli — dan
 * image dipangkas mengikuti daftar itu. Bentuk itu dicabut: yang dibagikan ke klien sekarang **satu
 * image per rilis**, berisi seluruh modul, dan yang menentukan modul mana yang boleh dibuka sebuah
 * tenant adalah lisensi yang diterbitkan admin.erp. Alasannya di
 * `docs/todo/registry-harbor/README.md`.
 *
 * Yang tersisa dari aturan lama justru yang paling penting, dan ia tidak bergantung pada pelanggan
 * sama sekali: **modul bahan uji tidak pernah ikut**. Modul ber-`kind: internal-fixture` hidup di
 * repo untuk menguji penjaga batas, dan sebuah menu bernama "Contoh A" di layar klien adalah
 * kegagalan yang tidak boleh mungkin terjadi.
 *
 * Aturan lama yang ikut hilang bersama edisi — penutupan dependency transitif dan modul penghubung
 * yang menunggu kedua sisinya — hilang karena keduanya menjadi hampa, bukan karena dilonggarkan:
 * begitu setiap modul ikut, setiap dependency sudah pasti ikut dan setiap sisi sebuah penghubung
 * sudah pasti ada. Menyimpannya berarti menyimpan kode yang tidak dapat lagi salah, dan kode yang
 * tidak dapat salah tidak dapat diuji.
 *
 * Ia sengaja tidak menyentuh database. Image dibangun di CI tanpa database sama sekali, dan sebuah
 * perintah yang diam-diam membutuhkan koneksi akan gagal di sana dengan pesan yang tidak menyebut
 * modul sedikit pun.
 */
final readonly class EditionModules
{
    public function __construct(private ModuleRegistry $registry) {}

    /**
     * Id modul yang ikut ke dalam image, sudah diurutkan.
     *
     * @return list<string>
     */
    public function daftar(): array
    {
        $semua = $this->registry->semuaTermasukYangSedangDipindah();

        // Nol manifest berarti pemindaiannya salah alamat — folder `modules/` bergeser, pola
        // globnya meleset, atau perintahnya dijalankan dari akar yang bukan repo. Daftar kosong
        // yang dipulangkan diam-diam akan membangun image berisi Core saja, dan image itu lulus
        // setiap pemeriksaan kebocoran karena memang tidak ada yang bocor. Kegagalannya baru
        // terlihat sebagai menu yang hilang di layar klien.
        if ($semua === []) {
            throw new RuntimeException(
                'Tidak satu pun manifest modul terbaca di folder `modules/`. '.
                'Pemindaiannya salah alamat, dan daftar kosong yang dipulangkan diam-diam akan '.
                'menghasilkan image berisi Core saja tanpa satu pun kesalahan.'
            );
        }

        $hasil = [];

        foreach ($semua as $manifest) {
            if ($manifest->bahanUjiInternal()) {
                continue;
            }

            $hasil[] = $manifest->id;
        }

        sort($hasil);

        return $hasil;
    }
}
