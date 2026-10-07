<?php

declare(strict_types=1);

namespace App\Platform\Analytics\External;

use App\Platform\Analytics\Query\AnalyticsQueryException;

/**
 * Galat publikasi untuk sistem luar, dalam bentuk galat engine (`{"error": {"code", "message", "field"}}`) supaya
 * satu endpoint hanya punya satu bentuk galat di luar penolakan gerbang klien integrasi. Daftar kodenya ditulis
 * di kontrak `integrasi-analitik.yaml` bagian *Kode galat* dan model `AnalyticsErrorCode`; menambah kode berarti
 * menambah keduanya.
 *
 * Pesannya untuk developer sistem luar: menyebut apa yang terjadi dan siapa yang dapat memperbaikinya, tanpa
 * membocorkan publikasi yang tidak dibuka untuk klien itu.
 */
final class PublicationErrors
{
    /** Pembuka dan penutup pesan `unavailable`; layar publikasi menampilkan alasannya saja. */
    public const UNAVAILABLE_PREFIX = 'Publikasi ini tidak dapat dibaca sekarang: ';

    public const UNAVAILABLE_SUFFIX = ' Minta admin tenant memperbaikinya.';

    /** Tidak ada, sudah dicabut, atau tidak membuka klien ini — sengaja tidak dibedakan. */
    public static function unknown(): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.publication_unknown', 'Publikasi ini tidak ada atau tidak dibuka untuk klien integrasi ini.', 404, 'code');
    }

    public static function paused(): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.publication_paused', 'Publikasi ini sedang dihentikan sementara oleh admin tenant. Coba lagi setelah dilanjutkan.', 403);
    }

    /** Pemiliknya keluar dari tenant atau kehilangan hak publikasi maupun hak membaca datanya. */
    public static function suspended(): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.publication_suspended', 'Publikasi ini tertahan karena pemiliknya tidak lagi berhak membagikan datanya. Minta admin tenant mengambil alih publikasi ini.', 403);
    }

    /** Data atau kolom yang dipublikasikan tidak dapat dihitung lagi; perlu diperbaiki pemiliknya di CoreERP. */
    public static function unavailable(string $reason): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.publication_unavailable', self::UNAVAILABLE_PREFIX.$reason.self::UNAVAILABLE_SUFFIX, 409);
    }

    /** Alasan di dalam pesan `unavailable`, untuk layar publikasi; pesan lain dipulangkan utuh. */
    public static function reason(AnalyticsQueryException $e): string
    {
        $message = $e->getMessage();
        if ($e->errorCode !== 'analytics.publication_unavailable') {
            return $message;
        }

        return ucfirst(substr($message, strlen(self::UNAVAILABLE_PREFIX), -strlen(self::UNAVAILABLE_SUFFIX)));
    }

    public static function formatUnavailable(string $format): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.format_unavailable', 'Publikasi ini tidak dibuka dalam format '.$format.'. Lihat daftar formats di metadata publikasi.', 422, 'format');
    }

    public static function cursorInvalid(): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.cursor_invalid', 'Cursor tidak dikenal atau tidak cocok dengan saringan permintaan ini. Mulai lagi dari halaman pertama.', 422, 'cursor');
    }

    public static function invalidParameter(string $field, string $message): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.invalid_parameter', $message, 422, $field);
    }

    public static function filterNotAllowed(string $key): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.invalid_filter', 'Kolom "'.$key.'" tidak dapat disaring di publikasi ini. Lihat daftar filterable di metadata publikasi.', 422, 'filter.'.$key);
    }
}
