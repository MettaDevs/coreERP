<?php

declare(strict_types=1);

namespace App\Support\Observabilitas;

use Throwable;

/**
 * Menunjuk nilai mana yang paling mungkin menyebabkan sebuah kolom kepenuhan.
 *
 * **Masalah yang diselesaikan.** Driver hanya berkata *"String or binary data would be
 * truncated"* atau *"value too long for type character varying"*. Ia tidak menyebut kolom
 * mana, apalagi nilai mana. Pada sebuah `insert` dengan delapan belas kolom, itu berarti
 * orang yang membaca laporan tetap harus menghitung sendiri tanda tanya satu per satu sambil
 * mencocokkannya dengan daftar binding — pekerjaan yang selalu dikerjakan tengah malam, dan
 * selalu salah hitung pada percobaan pertama.
 *
 * Kelas ini mengerjakan penghitungan itu sekali, saat kejadiannya masih segar: memasangkan
 * nama kolom dari query dengan nilai binding pada urutan yang sama, lalu mengurutkannya dari
 * yang terpanjang. Yang teratas hampir selalu tersangkanya.
 *
 * **Ia menyebut "tersangka", bukan "penyebab", dan itu disengaja.** Tanpa membaca skema kita
 * tidak tahu batas tiap kolom; sebuah kolom `varchar(10)` yang diisi 12 karakter kalah
 * panjang dari `text` berisi 400 karakter yang sama sekali tidak bermasalah. Menyebutnya
 * penyebab berarti berbohong dengan percaya diri — dan laporan yang salah menunjuk lebih
 * buruk daripada laporan yang tidak menunjuk apa-apa.
 */
final class TersangkaPemotongan
{
    /** Berapa banyak kolom teratas yang ditampilkan. Lebih dari ini hanya jadi kebisingan. */
    private const JUMLAH = 5;

    /** SQLSTATE untuk data string yang terlalu panjang; sama di PostgreSQL dan SQL Server. */
    public const SQLSTATE = '22001';

    /**
     * Apakah kesalahan ini soal nilai yang tidak muat.
     *
     * Diperiksa lewat dua jalan karena tidak semua driver mengisi SQLSTATE dengan rapi —
     * pesan ODBC SQL Server, misalnya, kerap datang dengan kode yang dibungkus.
     */
    public static function cocok(?string $sqlstate, ?string $pesan): bool
    {
        if ($sqlstate === self::SQLSTATE) {
            return true;
        }

        if ($pesan === null) {
            return false;
        }

        return preg_match('/would be truncated|value too long|data right truncated/i', $pesan) === 1;
    }

    /**
     * Batas kolom yang disebutkan driver, kalau ia menyebutkannya.
     *
     * PostgreSQL menuliskannya: *"value too long for type character varying(8)"*. SQL Server
     * lewat ODBC tidak — pesannya berhenti pada *"String or binary data would be truncated"*.
     * Karena itu nilainya boleh `null`, dan pemanggilnya harus tetap berguna tanpanya.
     */
    public static function batasDariPesan(?string $pesan): ?int
    {
        if ($pesan === null) {
            return null;
        }

        if (preg_match('/(?:character varying|varchar|character|char|nvarchar|nchar)\s*\(\s*(\d+)\s*\)/i', $pesan, $cocok) === 1) {
            return (int) $cocok[1];
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $binding
     * @param  int|null  $batas  panjang maksimum kolom, bila driver menyebutkannya
     * @return list<array{kolom: string, panjang: int, cuplikan: string, melebihi: bool}>
     */
    public static function daftar(string $sql, array $binding, ?int $batas = null): array
    {
        try {
            $kolom = self::kolomDariSql($sql);
            $nilai = array_values($binding);

            $baris = [];

            foreach ($nilai as $urutan => $isi) {
                if (! is_string($isi)) {
                    continue;
                }

                $panjang = mb_strlen($isi);

                $baris[] = [
                    'kolom' => $kolom[$urutan] ?? ('#'.($urutan + 1)),
                    'panjang' => $panjang,
                    'cuplikan' => $panjang > 60 ? mb_substr($isi, 0, 60).'…' : $isi,
                    'melebihi' => $batas !== null && $panjang > $batas,
                ];
            }

            // Urutannya menentukan apakah laporan ini menolong atau menyesatkan.
            //
            // Sekadar mengurutkan dari yang terpanjang menunjuk kolom yang salah. Terukur pada
            // 11 September 2026 dengan kesalahan sungguhan: `catatan` bertipe `text` berisi 320
            // karakter berada di atas `kode_satuan` yang `varchar(8)` berisi 20 — padahal yang
            // pertama sehat dan yang kedua penyebabnya.
            //
            // Menyaring dengan batas kolom saja juga tidak cukup: ketika batasnya 8, keduanya
            // sama-sama melebihi. Yang membedakan adalah **kedekatannya dengan batas** — nilai
            // 20 pada batas 8 jauh lebih mungkin berasal dari kolom yang memang dideklarasikan
            // sesempit itu daripada nilai 320, yang bentuknya khas isi kolom `text`.
            //
            // Ini tetap dugaan, bukan jawaban; nama kelas ini menyebut "tersangka" karena itu.
            // Jawaban pasti butuh membaca skema, dan membaca skema berarti bertanya ke database
            // dari dalam penangan kesalahan — harga yang belum sepadan untuk satu baris urutan.
            usort($baris, static function (array $a, array $b) use ($batas): int {
                if ($a['melebihi'] !== $b['melebihi']) {
                    return $b['melebihi'] <=> $a['melebihi'];
                }

                if ($batas !== null && $a['melebihi'] && $b['melebihi']) {
                    return $a['panjang'] <=> $b['panjang'];
                }

                return $b['panjang'] <=> $a['panjang'];
            });

            return array_slice($baris, 0, self::JUMLAH);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Menarik nama kolom dari query, pada urutan yang sama dengan binding-nya.
     *
     * Dua bentuk yang ditangani, karena keduanya yang menghasilkan kesalahan ini di praktik:
     * `insert into … ("a", "b") values (?, ?)` dan `update … set "a" = ?, "b" = ?`.
     *
     * Kalau bentuknya lain — subquery, `insert … select`, `on conflict` dengan ekspresi —
     * kelas ini mengembalikan daftar kosong, dan pemanggilnya jatuh ke penomoran `#1`, `#2`.
     * Menebak nama kolom dari query yang tidak dipahami akan menempelkan nama yang salah pada
     * nilai yang benar, yang justru mengirim orang ke arah keliru.
     *
     * @return list<string>
     */
    private static function kolomDariSql(string $sql): array
    {
        if (preg_match('/insert\s+into\s+\S+\s*\((?<kolom>[^)]*)\)\s*values/i', $sql, $cocok) === 1) {
            return self::pecah($cocok['kolom']);
        }

        if (preg_match('/\bset\b(?<set>.+?)(?:\bwhere\b|$)/is', $sql, $cocok) === 1) {
            $kolom = [];

            foreach (explode(',', $cocok['set']) as $bagian) {
                if (preg_match('/([`"\[]?)([A-Za-z0-9_]+)\1?\s*=\s*\?/', $bagian, $satu) === 1) {
                    $kolom[] = $satu[2];
                }
            }

            return $kolom;
        }

        return [];
    }

    /** @return list<string> */
    private static function pecah(string $daftar): array
    {
        $hasil = [];

        foreach (explode(',', $daftar) as $satu) {
            $bersih = trim($satu);
            $bersih = trim($bersih, '"`[]\' ');

            if ($bersih !== '') {
                $hasil[] = $bersih;
            }
        }

        return $hasil;
    }
}
