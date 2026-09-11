<?php

declare(strict_types=1);

namespace App\Support\Observabilitas;

use DateTimeInterface;
use Throwable;

/**
 * Menyusun ulang sebuah query menjadi bentuk yang bisa dibaca manusia, dengan nilai
 * binding-nya disisipkan ke tempat tanda tanya.
 *
 * **Kenapa tidak memakai `QueryException::getRawSql()`.** Method bawaan itu memanggil
 * `DB::connection($nama)->getQueryGrammar()`, yang berarti menyelesaikan sebuah koneksi
 * database lewat container — dari dalam penangan kesalahan, untuk sebuah kesalahan yang
 * mungkin justru berupa kegagalan koneksi. Menanyakan pada database kenapa database gagal
 * adalah cara satu kesalahan berubah menjadi dua, dan pada database yang mati ia membayar
 * satu batas waktu koneksi untuk setiap laporan.
 *
 * Kelas ini tidak menyentuh I/O apa pun, jadi ia tidak bisa gagal karena sebab di luar
 * dirinya. Yang ditukar adalah ketepatan pengutipan menurut tata bahasa masing-masing
 * driver. Untuk laporan yang dibaca orang, itu harga yang pantas.
 *
 * **Aturan paling penting ada pada penolakannya.** Kalau jumlah tanda tanya tidak sama
 * dengan jumlah binding, kelas ini menyerah dan mengembalikan `null` alih-alih menebak.
 * Query hasil rekonstruksi yang salah tetapi tampak yakin lebih berbahaya daripada tidak
 * ada rekonstruksi sama sekali: yang pertama mengirim orang menelusuri baris data yang
 * tidak pernah terlibat.
 */
final class SqlTerbaca
{
    /**
     * Panjang maksimum hasil. Query yang lebih panjang dari ini hampir selalu berupa
     * `insert` massal, dan seluruh isinya tidak menambah apa pun yang belum terlihat pada
     * seribu karakter pertama — sementara ia sanggup membuat satu laporan memenuhi disk.
     */
    private const BATAS = 8000;

    /**
     * @param  array<array-key, mixed>  $binding
     */
    public static function gabungkan(string $sql, array $binding): ?string
    {
        try {
            if (substr_count($sql, '?') !== count($binding)) {
                return null;
            }

            if ($binding === []) {
                return self::potong($sql);
            }

            $hasil = '';
            $sisa = $sql;

            foreach ($binding as $nilai) {
                $posisi = strpos($sisa, '?');

                // Tidak mungkin terjadi setelah pemeriksaan jumlah di atas, tetapi kalau toh
                // terjadi, menyerah tetap lebih baik daripada menghasilkan potongan query.
                if ($posisi === false) {
                    return null;
                }

                $hasil .= substr($sisa, 0, $posisi).self::harfiah($nilai);
                $sisa = substr($sisa, $posisi + 1);
            }

            return self::potong($hasil.$sisa);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Mengubah satu nilai binding menjadi bentuk harfiah SQL.
     */
    private static function harfiah(mixed $nilai): string
    {
        if ($nilai === null) {
            return 'NULL';
        }

        if (is_bool($nilai)) {
            return $nilai ? 'true' : 'false';
        }

        if (is_int($nilai) || is_float($nilai)) {
            return (string) $nilai;
        }

        if ($nilai instanceof DateTimeInterface) {
            return self::kutip($nilai->format('Y-m-d H:i:s.uP'));
        }

        if (is_object($nilai) && method_exists($nilai, '__toString')) {
            return self::kutip((string) $nilai);
        }

        if (! is_string($nilai)) {
            return self::kutip(gettype($nilai));
        }

        // Binding biner — hasil `bindValue` dengan PDO::PARAM_LOB, atau kolom bytea — tidak
        // punya bentuk terbaca dan menempelkannya apa adanya merusak berkas log yang memuatnya.
        if (! mb_check_encoding($nilai, 'UTF-8')) {
            return sprintf('<biner %d bita>', strlen($nilai));
        }

        return self::kutip($nilai);
    }

    private static function kutip(string $nilai): string
    {
        return "'".str_replace("'", "''", $nilai)."'";
    }

    private static function potong(string $sql): string
    {
        if (mb_strlen($sql) <= self::BATAS) {
            return $sql;
        }

        return mb_substr($sql, 0, self::BATAS).' … (dipotong)';
    }
}
