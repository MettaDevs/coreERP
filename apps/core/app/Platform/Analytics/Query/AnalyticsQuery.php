<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Query analitik yang sudah dibaca dan dinormalkan. Tidak berubah setelah dibuat: kunci cache dihitung
 * dari bentuk normalnya, jadi dua JSON yang berbeda urutan kuncinya menjadi satu entri cache.
 *
 * Bentuk konstruktor ini dibekukan area 0 dan dipakai bersama area 2, 3, 6, dan 9. Area 0 hanya mengisi
 * `dataset`, `dimensions` tanpa ember waktu, `measures`, `filters`, dan `limit`; sisanya bernilai bawaan
 * sampai area 2 membacanya dari JSON.
 */
final readonly class AnalyticsQuery
{
    /**
     * @param  list<Dimension>  $dimensions
     * @param  list<string>  $measures
     * @param  array<string, string|list<string>>  $filters
     * @param  list<array{key: string, direction: 'asc'|'desc'}>  $sort
     */
    public function __construct(
        public string $dataset,
        public array $dimensions,
        public array $measures,
        public array $filters,
        public ?TimeRange $timeRange,
        public array $sort,
        public ?int $limit,
        public bool $totals,
        public bool $fillGaps,
    ) {}

    /**
     * Bentuk normal untuk kunci cache dan log: kunci urut, nilai daftar urut, tanpa nilai bawaan.
     *
     * @return array<string, mixed>
     */
    public function normalized(): array
    {
        $filters = $this->filters;
        ksort($filters);
        foreach ($filters as &$value) {
            if (is_array($value)) {
                sort($value);
            }
        }
        unset($value);

        return [
            'dataset' => $this->dataset,
            'dimensions' => array_map(fn (Dimension $d): array => $d->toArray(), $this->dimensions),
            'measures' => $this->measures,
            'filters' => $filters,
            'time_range' => $this->timeRange?->toArray(),
            'sort' => $this->sort,
            'limit' => $this->limit,
            'totals' => $this->totals,
            'fill_gaps' => $this->fillGaps,
        ];
    }
}
