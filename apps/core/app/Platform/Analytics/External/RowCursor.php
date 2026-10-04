<?php

declare(strict_types=1);

namespace App\Platform\Analytics\External;

use App\Platform\Analytics\Query\AnalyticsQueryException;

/**
 * Cursor halaman baris publikasi: posisi baris berikutnya dalam hasil yang urutannya pasti, diikat ke publikasi,
 * versinya, dan query efektif permintaan (termasuk saringan tambahan pemanggil).
 *
 * Hasil analitik adalah kelompok, bukan baris tabel, jadi tidak ada kunci baris untuk cursor keyset. Urutannya
 * tetap pasti karena compiler selalu mengurutkan menurut setiap kolom pengelompok sebagai pemutus seri, sehingga
 * posisi berarti baris yang sama selama datanya tidak berubah. Cursor yang dipakai dengan saringan lain, atau
 * sesudah publikasinya diubah, ditolak 422 `analytics.cursor_invalid` alih-alih diam-diam melompati baris.
 *
 * Bentuknya base64url dari JSON `{"o": posisi, "s": cakupan}` — opak bagi pemanggil, tanpa rahasia di dalamnya.
 */
final class RowCursor
{
    /** Panjang tertinggi cursor yang diterima, sebelum dibaca. */
    public const MAX_LENGTH = 200;

    /**
     * Cakupan cursor: publikasi, versinya, dan bentuk normal query efektif.
     *
     * @param  array<string, mixed>  $normalizedQuery
     */
    public static function scope(string $publicationId, int $publicationVersion, array $normalizedQuery): string
    {
        return substr(hash('sha256', $publicationId.'|'.$publicationVersion.'|'.json_encode($normalizedQuery, JSON_THROW_ON_ERROR)), 0, 24);
    }

    public static function encode(int $offset, string $scope): string
    {
        return rtrim(strtr(base64_encode(json_encode(['o' => $offset, 's' => $scope], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @throws AnalyticsQueryException `analytics.cursor_invalid` */
    public static function decode(string $cursor, string $scope): int
    {
        $json = strlen($cursor) > self::MAX_LENGTH ? false : base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_int($data['o'] ?? null) || $data['o'] < 1 || ($data['s'] ?? null) !== $scope) {
            throw PublicationErrors::cursorInvalid();
        }

        return $data['o'];
    }
}
