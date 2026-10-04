<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;

/**
 * Hasil query analitik dalam bentuk yang dikirim ke layar dan pemanggil luar. Bentuknya dibekukan area 0
 * dan ditulis di `docs/todo/analitik/mesin-query.md` bagian *Bentuk hasil*, beserta tipe TypeScript-nya
 * di `resources/js/lib/analytics/types.ts`.
 *
 * - Alias SQL (`d0`, `d0_label`, `c0`, `m0`) dipetakan kembali ke kunci dataset; alias tidak pernah keluar
 *   dari server.
 * - Jumlah baris (`count`) dikirim sebagai angka; nilai lain — uang, desimal — sebagai **string**, karena
 *   `numeric` PostgreSQL lebih presisi daripada angka JavaScript. Layar memformatnya, bukan menghitungnya.
 * - Dimensi berlabel membawa label di kolom pendamping `<kunci>__label` ({@see LabelResolver}); nilai
 *   mentahnya tetap dikirim untuk saringan dan drill.
 * - Periode dikirim sebagai tanggal awal ember (`2026-09-01`); celah deret waktunya diisi {@see GapFiller}.
 * - `totals` berupa daftar, satu baris per mata uang dan satuan; kosong bila tidak diminta.
 */
final readonly class ResultSet
{
    /**
     * @param  list<ResultColumn>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     * @param  list<array<string, scalar|null>>  $totals
     * @param  array{dataset: string, dataset_version: int, generated_at: string, timezone: string, truncated: bool, row_limit: int, cached: bool, duration_ms: int, query_hash: string}  $meta
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public array $totals,
        public array $meta,
    ) {}

    /**
     * @param  array{rows: list<object>, totals: list<object>}  $executed
     */
    public static function from(CompiledDataset $dataset, AnalyticsQuery $query, CompiledQuery $compiled, array $executed, AnalyticsPrincipal $principal, int $durationMs, LabelResolver $labels): self
    {
        $rows = $executed['rows'];
        $truncated = count($rows) > $compiled->limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $compiled->limit);
        }

        $map = static fn (object $row): array => self::row($compiled->columns, $row);
        $rows = $labels->apply($dataset, $compiled->columns, array_map($map, $rows), $principal);
        // Hasil terpotong tidak diisi: periode yang hilang mungkin terpotong, bukan kosong.
        if (! $truncated) {
            $rows = GapFiller::fill($dataset, $query, $compiled->columns, $rows, $principal->now(), $compiled->limit);
        }

        return new self(
            columns: $compiled->columns,
            rows: $rows,
            totals: $labels->apply($dataset, $compiled->columns, array_map($map, $executed['totals']), $principal),
            meta: [
                'dataset' => $dataset->code,
                'dataset_version' => $dataset->version,
                'generated_at' => $principal->now()->toIso8601String(),
                'timezone' => $principal->timezone(),
                'truncated' => $truncated,
                'row_limit' => $compiled->limit,
                'cached' => false,
                'duration_ms' => $durationMs,
                'query_hash' => 'sha256:'.hash('sha256', json_encode($query->normalized(), JSON_THROW_ON_ERROR)),
            ],
        );
    }

    /**
     * Bentuk yang disimpan cache hasil area 9 (`Cache\QueryCache`): seperti {@see self::toArray()}, tetapi
     * kolomnya lengkap ({@see ResultColumn::toCache()}), supaya hasil yang dibaca kembali sama dengan hasil
     * yang dihitung. Bentuk ini ikut versi kunci cache; mengubahnya berarti menaikkan versi itu.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, scalar|null>>, totals: list<array<string, scalar|null>>, meta: array{dataset: string, dataset_version: int, generated_at: string, timezone: string, truncated: bool, row_limit: int, cached: bool, duration_ms: int, query_hash: string}}
     */
    public function toCache(): array
    {
        return [
            'columns' => array_map(static fn (ResultColumn $column): array => $column->toCache(), $this->columns),
            'rows' => $this->rows,
            'totals' => $this->totals,
            'meta' => $this->meta,
        ];
    }

    /**
     * Hasil yang dibaca kembali dari cache: isinya persis hasil yang dihitung, termasuk `generated_at` — layar
     * menulis "Dihitung pukul …" dari situ — dengan `meta.cached` menyala. `$payload` adalah bentuk
     * {@see self::toCache()} yang sudah didekode dari JSON.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromCache(array $payload): self
    {
        /** @var list<array{alias: string, key: string, kind: 'dimension'|'measure', caption: string, type: string, format: ?string, granularity: ?string, label_key: ?string, currency_key: ?string, unit_key: ?string, implicit: bool, aggregate: ?string, label_alias: ?string}> $columns */
        $columns = $payload['columns'];
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $payload['rows'];
        /** @var list<array<string, scalar|null>> $totals */
        $totals = $payload['totals'];
        /** @var array{dataset: string, dataset_version: int, generated_at: string, timezone: string, truncated: bool, row_limit: int, cached: bool, duration_ms: int, query_hash: string} $meta */
        $meta = $payload['meta'];
        $meta['cached'] = true;

        return new self(
            columns: array_map(static fn (array $column): ResultColumn => ResultColumn::fromCache($column), $columns),
            rows: $rows,
            totals: $totals,
            meta: $meta,
        );
    }

    /**
     * @return array{columns: list<array<string, string|bool>>, rows: list<array<string, scalar|null>>, totals: list<array<string, scalar|null>>, meta: array{dataset: string, dataset_version: int, generated_at: string, timezone: string, truncated: bool, row_limit: int, cached: bool, duration_ms: int, query_hash: string}}
     */
    public function toArray(): array
    {
        return [
            'columns' => array_map(static fn (ResultColumn $column): array => $column->toArray(), $this->columns),
            'rows' => $this->rows,
            'totals' => $this->totals,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param  list<ResultColumn>  $columns
     * @return array<string, scalar|null>
     */
    private static function row(array $columns, object $row): array
    {
        $values = get_object_vars($row);
        $out = [];

        foreach ($columns as $column) {
            // Baris total tidak membawa dimensi selain mata uang dan satuan.
            if (! array_key_exists($column->alias, $values)) {
                continue;
            }

            $value = $values[$column->alias];
            $out[$column->key] = match (true) {
                $value === null => null,
                $column->kind === ResultColumn::MEASURE && $column->counts() => (int) $value,
                $column->kind === ResultColumn::MEASURE, ! is_scalar($value) => (string) $value,
                default => $value,
            };

            // Label rujukan module dari join label; label lain diisi LabelResolver di tempat ini, tepat sesudah
            // nilainya.
            if ($column->labelKey !== null) {
                $label = $column->labelAlias === null ? null : ($values[$column->labelAlias] ?? null);
                $out[$column->labelKey] = $label === null ? null : (string) $label;
            }
        }

        return $out;
    }
}
