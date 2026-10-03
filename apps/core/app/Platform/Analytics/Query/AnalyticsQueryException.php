<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Query yang tidak dapat dijalankan karena sebab yang dapat ditindaklanjuti pemanggilnya: data tidak
 * tersedia, tanpa akses, kolom tidak dikenal, saringan tidak terbaca, atau perhitungan terlalu berat.
 *
 * Bentuk jawabannya `{"error": {"code", "message", "field"}}`, yang sudah dibaca `CoreApiError` di layar.
 * Pesannya bahasa sehari-hari yang menyebut apa yang dapat dilakukan pengguna; `field` menunjuk bagian
 * query yang salah (`filters.nama`, `dimensions.1`) bila ada.
 *
 * Cacat engine — compiler yang menulis, SQL yang tidak sah — sengaja **tidak** menjadi pengecualian ini:
 * ia dibiarkan menjadi 500 dan dilaporkan, karena bukan kesalahan pengguna. Daftar kodenya ada di
 * `docs/todo/analitik/mesin-query.md` bagian *Galat*.
 */
final class AnalyticsQueryException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?string $field = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidQuery(string $field, string $message): self
    {
        return new self('analytics.invalid_query', $message, 422, $field);
    }

    public static function datasetUnknown(): self
    {
        return new self('analytics.dataset_unknown', 'Data ini tidak tersedia. Aplikasinya mungkin belum terpasang.', 404, 'dataset');
    }

    public static function datasetForbidden(): self
    {
        return new self('analytics.dataset_forbidden', 'Anda tidak punya akses ke data ini.', 403, 'dataset');
    }

    public static function fieldUnknown(string $field, string $key): self
    {
        return new self('analytics.field_unknown', 'Kolom "'.$key.'" tidak dikenal. Pilih kolom dari daftar.', 422, $field);
    }

    public static function measureUnknown(string $field, string $key): self
    {
        return new self('analytics.field_unknown', 'Nilai "'.$key.'" tidak dikenal. Pilih nilai dari daftar.', 422, $field);
    }

    public static function invalidFilter(string $field, string $message, ?Throwable $previous = null): self
    {
        return new self('analytics.invalid_filter', $message, 422, $field, $previous);
    }

    public static function limitExceeded(string $field, string $message): self
    {
        return new self('analytics.limit_exceeded', $message, 422, $field);
    }

    /**
     * Galat database yang bermakna bagi pengguna, atau null untuk yang bukan: pemanggil melempar ulang
     * pengecualian aslinya, sehingga cacat engine tetap menjadi 500 yang dilaporkan.
     */
    public static function fromDatabase(QueryException $e): ?self
    {
        return match ((string) $e->getCode()) {
            // statement_timeout tercapai.
            '57014' => new self('analytics.query_timeout', 'Perhitungan ini terlalu berat. Persempit periode atau saringan.', 422, null, $e),
            // Nilai yang tidak dapat dibaca sebagai angka atau tanggal oleh database.
            '22P02', '22007', '22008' => new self('analytics.invalid_filter', 'Saringan tidak dapat dibaca sebagai angka atau tanggal. Periksa isian saringan.', 422, 'filters', $e),
            default => null,
        };
    }

    /** @return array{error: array{code: string, message: string, field?: string}} */
    public function toArray(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->field !== null) {
            $error['field'] = $this->field;
        }

        return ['error' => $error];
    }

    public function toResponse(): JsonResponse
    {
        return response()->json($this->toArray(), $this->status);
    }
}
