<?php

declare(strict_types=1);

namespace App\Platform\Observability\Support;

use DateTimeInterface;
use Throwable;

/**
 * Menyusun ulang sebuah query menjadi bentuk yang bisa dibaca manusia, dengan nilai
 * binding-nya disisipkan ke tempat tanda tanya.
 *
 * **Kenapa tidak memakai `QueryException::getRawSql()`.** Method bawaan itu memanggil
 * `DB::connection($name)->getQueryGrammar()`, yang berarti menyelesaikan sebuah koneksi
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
final class ReadableSql
{
    /**
     * Panjang maksimum hasil. Query yang lebih panjang dari ini hampir selalu berupa
     * `insert` massal, dan seluruh isinya tidak menambah apa pun yang belum terlihat pada
     * seribu karakter pertama — sementara ia sanggup membuat satu laporan memenuhi disk.
     */
    private const LIMIT = 8000;

    /**
     * @param  array<array-key, mixed>  $binding
     */
    public static function interpolate(string $sql, array $binding): ?string
    {
        try {
            if (substr_count($sql, '?') !== count($binding)) {
                return null;
            }

            if ($binding === []) {
                return self::truncate($sql);
            }

            $result = '';
            $remaining = $sql;

            foreach ($binding as $value) {
                $position = strpos($remaining, '?');

                // Tidak mungkin terjadi setelah pemeriksaan jumlah di atas, tetapi kalau toh
                // terjadi, menyerah tetap lebih baik daripada menghasilkan potongan query.
                if ($position === false) {
                    return null;
                }

                $result .= substr($remaining, 0, $position).self::literal($value);
                $remaining = substr($remaining, $position + 1);
            }

            return self::truncate($result.$remaining);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Mengubah satu nilai binding menjadi bentuk harfiah SQL.
     */
    private static function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return self::quote($value->format('Y-m-d H:i:s.uP'));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return self::quote((string) $value);
        }

        if (! is_string($value)) {
            return self::quote(gettype($value));
        }

        // Binding biner — hasil `bindValue` dengan PDO::PARAM_LOB, atau kolom bytea — tidak
        // punya bentuk terbaca dan menempelkannya apa adanya merusak berkas log yang memuatnya.
        if (! mb_check_encoding($value, 'UTF-8')) {
            return sprintf('<biner %d bita>', strlen($value));
        }

        return self::quote($value);
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private static function truncate(string $sql): string
    {
        if (mb_strlen($sql) <= self::LIMIT) {
            return $sql;
        }

        return mb_substr($sql, 0, self::LIMIT).' … (dipotong)';
    }
}
