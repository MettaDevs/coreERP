<?php

declare(strict_types=1);

namespace App\Support\ControlPlane;

/**
 * Penanda bahwa sebuah tabel hidup di sisi **pusat**, bukan di sisi environment.
 *
 * Ia cerminan `MilikTenant`. Yang itu menandai tabel milik satu tenant dan menyaringnya; yang ini
 * menandai tabel yang justru **tidak** milik tenant mana pun — identitas, pelanggan, daftar tenant,
 * registry environment, dan akses operator. Semuanya global terhadap seluruh tenant, dan karena itu
 * tidak pernah ikut berpindah ketika sebuah environment disalin.
 *
 * ## Kenapa ia ada sekarang, padahal belum mengubah apa pun
 *
 * Hari ini `coreerp.control_connection` kosong, dan trait ini **tidak melakukan apa-apa**: seluruh
 * tabel tetap di koneksi bawaan, persis seperti sebelumnya. Ia didirikan lebih dulu karena
 * batasnya-lah yang mahal, bukan koneksinya. Menandai sisi pusat sekarang — selagi keduanya masih
 * satu database dan setiap test masih hijau — jauh lebih murah daripada menebaknya kelak ketika
 * keduanya sudah terpisah dan kesalahannya muncul sebagai tenant yang datanya tidak ditemukan.
 *
 * ## Kenapa bukan koneksi bernama di `config/database.php`
 *
 * Koneksi bernama harus menyalin seluruh setelan koneksi bawaan, dan salinan itu akan menyimpang
 * pada hari seseorang mengubah salah satunya saja. Lebih buruk lagi di test: suite memakai
 * `pgsql_test`, sehingga koneksi `control` yang menunjuk `pgsql` akan menjadi **PDO kedua** ke
 * database yang sama — dan transaksi milik `RefreshDatabase` hanya membungkus satu di antaranya,
 * sehingga data yang ditulis satu koneksi tidak terlihat oleh koneksi lainnya.
 *
 * Karena itu yang disimpan hanyalah **nama** koneksi, dan kosong berarti "ikut yang bawaan". On-prem
 * kosong selamanya: di sana memang tidak ada sisi pusat yang terpisah.
 */
trait OwnedByControlPlane
{
    public function getConnectionName(): ?string
    {
        $koneksi = config('coreerp.control_connection');

        if (is_string($koneksi) && $koneksi !== '') {
            return $koneksi;
        }

        return parent::getConnectionName();
    }
}
