<?php

namespace Modules\Apperp\ManagementAset\Services;

use Brick\Math\BigDecimal;
use stdClass;

/**
 * Angka saldo awal satu baris penerimaan untuk satu buku aset (feed posting finance, TODO 10.1.1,
 * K-28).
 *
 * Akumulasi dan periode berjalan di baris adalah angka buku yang di-post ke finance, sekaligus
 * bawaan setiap buku lain. Buku yang angkanya berbeda — lazimnya buku fiskal — tercatat di
 * `saldo_awal_buku`. Pembuat buku aset dan penyusun jurnal saldo awal sama-sama bertanya ke sini,
 * sehingga akumulasi di register dan di jurnal tidak pernah dibaca dari tempat yang berbeda.
 */
final class OpeningBalance
{
    /**
     * Angka saldo awal baris, dalam bentuk yang dipakai `PembuatAset`.
     *
     * @return array{default: array{accumulated: string, elapsed: int}, books: array<string, array{accumulated: string, elapsed: int}>}
     */
    public static function fromLine(stdClass $line): array
    {
        $books = [];
        foreach (self::overrides($line->saldo_awal_buku ?? null) as $book) {
            $books[(string) $book['buku_id']] = self::angka($book['akumulasi_per_unit'] ?? '0', $book['periode_berjalan'] ?? 0);
        }

        return [
            'default' => self::angka($line->akumulasi_per_unit ?? '0', $line->periode_berjalan ?? 0),
            'books' => $books,
        ];
    }

    /**
     * Akumulasi per unit dan periode berjalan satu buku.
     *
     * @param  array{default: array{accumulated: string, elapsed: int}, books: array<string, array{accumulated: string, elapsed: int}>}  $opening
     * @return array{accumulated: string, elapsed: int}
     */
    public static function pick(array $opening, string $bookId): array
    {
        return $opening['books'][$bookId] ?? $opening['default'];
    }

    /**
     * Baris `saldo_awal_buku` yang tersimpan: array dari model, atau teks JSON dari query builder.
     *
     * @return list<array{buku_id: string, akumulasi_per_unit?: mixed, periode_berjalan?: mixed}>
     */
    public static function overrides(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        $hasil = [];
        foreach ($value as $book) {
            if (is_array($book) && is_string($book['buku_id'] ?? null)) {
                $hasil[] = $book;
            }
        }

        return $hasil;
    }

    /** @return array{accumulated: string, elapsed: int} */
    private static function angka(mixed $accumulated, mixed $elapsed): array
    {
        return [
            'accumulated' => (string) BigDecimal::of(is_numeric($accumulated) ? (string) $accumulated : '0')->toScale(2),
            'elapsed' => is_numeric($elapsed) ? (int) $elapsed : 0,
        ];
    }
}
