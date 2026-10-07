<?php

declare(strict_types=1);

namespace App\Platform\Analytics\External;

/**
 * Baris publikasi sebagai CSV, dengan aturan ekspor daftar yang sudah ada (`Reporting\…\TypedSheetWriter`):
 * angka mentah dengan titik desimal, tanggal ISO, dan teks yang diawali karakter pemicu rumus diberi awalan
 * petik: `=`, `+`, `-`, `@`, tab, CR, LF, serta padanan lebar penuh `＝`, `＋`, `－`, `＠`.
 *
 * Penulis ekspor daftar tidak dipakai langsung karena ia menulis ke berkas dan mengubah uang menjadi float;
 * nilai uang analitik dikirim server sebagai teks desimal persis, dan CSV meneruskannya apa adanya. Angka negatif
 * tetap angka: hanya teks yang bukan angka yang diberi petik.
 *
 * Baris judul memuat kunci kolom, bukan judul tampilan, karena kunci tetap sedangkan judul dapat diganti module;
 * judul tampilan ada di metadata publikasi. Pemisah koma, baris diakhiri `\n`, tanpa BOM.
 */
final class CsvRows
{
    /**
     * @param  list<string>  $keys
     * @param  list<array<string, scalar|null>>  $rows
     */
    public static function render(array $keys, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Tidak dapat membuka penyangga CSV.');
        }

        fputcsv($out, $keys, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (string $key): string => self::cell($row[$key] ?? null), $keys), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private static function cell(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_string($value) || preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value) === 1) {
            return (string) $value;
        }

        return preg_match('/^[\x00-\x20\x7f]*[=+\-@＝＋－＠]/u', $value) === 1 ? "'".$value : $value;
    }
}
