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
 * - Alias SQL (`d0`, `c0`, `m0`) dipetakan kembali ke kunci dataset; alias tidak pernah keluar dari server.
 * - Jumlah baris (`count`) dikirim sebagai angka; nilai lain — uang, desimal — sebagai **string**, karena
 *   `numeric` PostgreSQL lebih presisi daripada angka JavaScript. Layar memformatnya, bukan menghitungnya.
 * - Field pilihan membawa label di kolom pendamping `<kunci>__label`; nilai mentahnya tetap dikirim untuk
 *   saringan dan drill.
 * - `totals` berupa daftar, satu baris per mata uang dan satuan. Kosong sampai area 3 menghitungnya.
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
    public static function from(CompiledDataset $dataset, AnalyticsQuery $query, CompiledQuery $compiled, array $executed, AnalyticsPrincipal $principal, int $durationMs): self
    {
        $rows = $executed['rows'];
        $truncated = count($rows) > $compiled->limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $compiled->limit);
        }

        return new self(
            columns: $compiled->columns,
            rows: array_map(static fn (object $row): array => self::row($dataset, $compiled->columns, $row), $rows),
            totals: array_map(static fn (object $row): array => self::row($dataset, $compiled->columns, $row), $executed['totals']),
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
    private static function row(CompiledDataset $dataset, array $columns, object $row): array
    {
        $values = get_object_vars($row);
        $out = [];

        foreach ($columns as $column) {
            // Baris total tidak membawa dimensi selain mata uang dan satuan.
            if (! array_key_exists($column->alias, $values)) {
                continue;
            }

            $value = $values[$column->alias];
            $value = match (true) {
                $value === null => null,
                $column->kind === ResultColumn::MEASURE && $column->counts => (int) $value,
                $column->kind === ResultColumn::MEASURE, ! is_scalar($value) => (string) $value,
                default => $value,
            };
            $out[$column->key] = $value;

            if ($column->labelKey !== null) {
                $options = $dataset->filterField($column->key)->options;
                $out[$column->labelKey] = $value === null ? null : ($options[(string) $value] ?? (string) $value);
            }
        }

        return $out;
    }
}
