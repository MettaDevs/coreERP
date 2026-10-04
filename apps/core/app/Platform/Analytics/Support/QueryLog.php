<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Support;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Models\QueryLogEntry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Modules\Contracts\DataClass;
use Throwable;

/**
 * Satu baris di `analytics_query_log` untuk setiap query yang sampai ke engine (area 9,
 * `docs/todo/analitik/keamanan.md` bagian *Log query*): tenant, principal, jalur masuk, dataset dan versinya,
 * bentuk normal query, durasi, jumlah baris, terpotong atau tidak, dari cache atau tidak, status, dan kode galat.
 * Query yang ditolak — tanpa hak, kolom tidak dikenal, jatah habis — ikut tercatat, karena penolakan itulah
 * yang dicari saat menelusuri akses.
 *
 * **Nilai saringan pada field data pribadi disamarkan** sebelum ditulis ({@see self::masked()}), karena log
 * dibaca operator yang tidak berhak atas data itu — siapa pun yang menjalankan query-nya. Yang disamarkan
 * adalah nilai pada field yang klasifikasinya bukan isi bisnis biasa (data pribadi, id orang, data akun), field
 * yang tidak dikenal dataset, dan seluruh nilai bila datasetnya tidak dikenal: tanpa klasifikasi, gagal
 * tertutup. Rentang waktu pada field data pribadi (tanggal lahir) disamarkan dengan cara yang sama.
 *
 * `query_hash` dihitung dari bentuk yang sudah disamarkan, bukan dari `meta.query_hash` hasil: hash bentuk
 * aslinya dapat ditebak ulang dari bentuk tersamar dengan mencoba nama-nama satu per satu.
 *
 * Retensi lewat kebijakan `analytics_query_log` (`RetentionPolicies`, bawaan 90 hari, minimum 7, PQ-05).
 */
final class QueryLog
{
    public const SOURCE_EXPLORE = 'explore';

    public const SOURCE_WIDGET = 'widget';

    public const SOURCE_API = 'api';

    public const SOURCE_ODATA = 'odata';

    public const SOURCE_EMBED = 'embed';

    public const SOURCE_JOB = 'job';

    /** Pengganti nilai saringan yang disamarkan. */
    public const MASK = '[disamarkan]';

    /** Klasifikasi field yang nilainya boleh tercatat apa adanya; selain ini disamarkan. */
    private const PLAIN = [DataClass::CustomerContent, DataClass::SystemMetadata, DataClass::OrganizationIdentifiableInformation];

    public function succeeded(AnalyticsPrincipal $principal, string $source, AnalyticsQuery $query, CompiledDataset $dataset, ResultSet $result, int $durationMs): void
    {
        $this->write($principal, $source, $query, $dataset, [
            'status' => 'success',
            'row_count' => count($result->rows),
            'truncated' => $result->meta['truncated'],
            'cached' => $result->meta['cached'],
        ], $durationMs);
    }

    public function failed(AnalyticsPrincipal $principal, string $source, AnalyticsQuery $query, ?CompiledDataset $dataset, Throwable $error, int $durationMs): void
    {
        $this->write($principal, $source, $query, $dataset, [
            'status' => 'failed',
            // Cacat engine tidak punya kode untuk pengguna; rinciannya di pelaporan galat, bukan di log ini.
            'error_code' => $error instanceof AnalyticsQueryException ? $error->errorCode : 'internal',
        ], $durationMs);
    }

    /**
     * Bentuk normal query dengan nilai saringan field data pribadi diganti {@see self::MASK}. Kunci field,
     * dimensi, measure, dan urutan tetap, karena ia nama kolom, bukan nilai.
     *
     * @return array<string, mixed>
     */
    public static function masked(AnalyticsQuery $query, ?CompiledDataset $dataset): array
    {
        $normalized = $query->normalized();

        // Disusun dari saringan bertipe dengan urutan yang sama dengan `AnalyticsQuery::normalized()`.
        $filters = [];
        foreach ($query->filters as $key => $value) {
            if (! self::plain($dataset, $key)) {
                $value = self::MASK;
            } elseif (is_array($value)) {
                sort($value, SORT_STRING);
            }
            $filters[$key] = $value;
        }
        ksort($filters);
        $normalized['filters'] = $filters;

        if ($query->timeRange !== null) {
            $field = $query->timeRange->field ?? $dataset?->defaultTime();
            if ($field === null || ! self::plain($dataset, $field)) {
                $normalized['time_range'] = ['field' => $query->timeRange->field, 'range' => self::MASK];
            }
        }

        return $normalized;
    }

    /**
     * @param  array{status: 'success'|'failed', row_count?: int, truncated?: bool, cached?: bool, error_code?: string}  $outcome
     */
    private function write(AnalyticsPrincipal $principal, string $source, AnalyticsQuery $query, ?CompiledDataset $dataset, array $outcome, int $durationMs): void
    {
        $masked = self::masked($query, $dataset);

        QueryLogEntry::query()->create([
            'tenant_id' => $principal->tenantId(),
            'principal' => mb_substr($principal->describe(), 0, 120),
            'source' => mb_substr($source, 0, 20),
            // Kode dataset yang tidak dikenal datang dari pemanggil, jadi panjangnya dibatasi kolomnya.
            'dataset_code' => mb_substr($dataset->code ?? $query->dataset, 0, 160),
            'dataset_version' => $dataset?->version,
            'query_hash' => hash('sha256', json_encode($masked, JSON_THROW_ON_ERROR)),
            'query' => $masked,
            'duration_ms' => max(0, $durationMs),
            ...$outcome,
        ]);
    }

    private static function plain(?CompiledDataset $dataset, string $key): bool
    {
        return $dataset !== null
            && $dataset->hasField($key)
            && in_array($dataset->classification($key), self::PLAIN, true);
    }
}
