<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Query\Formula\Formula;

/**
 * Query analitik yang sudah dibaca dan dinormalkan. Tidak berubah setelah dibuat: kunci cache dihitung
 * dari bentuk normalnya, jadi dua JSON yang berbeda urutan kuncinya menjadi satu entri cache.
 *
 * Bentuk konstruktor ini dibekukan area 0 dan dipakai bersama area 2, 3, 6, dan 9. Area 0 hanya mengisi
 * `dataset`, `dimensions` tanpa ember waktu, `measures`, `filters`, dan `limit`; area 2 membaca sisanya
 * dari JSON ({@see QueryParser}) dan menyatukan bentuknya ({@see QueryNormalizer}). Area 13 menambahkan tiga
 * isian di akhir, dengan bawaan kosong: rumus (`formulas`, kuncinya dipilih lewat `measures`), perbandingan
 * periode (`compare`), dan persen terhadap total (`percent_of_total`).
 */
final readonly class AnalyticsQuery
{
    /**
     * @param  list<Dimension>  $dimensions
     * @param  list<string>  $measures  kunci measure dataset atau kunci rumus
     * @param  array<string, string|list<string>>  $filters
     * @param  list<array{key: string, direction: 'asc'|'desc'}>  $sort
     * @param  list<Formula>  $formulas
     * @param  list<string>  $percentOfTotal  kunci di `measures` yang juga ditampilkan sebagai persen terhadap total
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
        public array $formulas = [],
        public ?CompareMode $compare = null,
        public array $percentOfTotal = [],
    ) {}

    /** Rumus berkunci ini, atau null bila kunci itu measure dataset. */
    public function formula(string $key): ?Formula
    {
        foreach ($this->formulas as $formula) {
            if ($formula->key === $key) {
                return $formula;
            }
        }

        return null;
    }

    /** Query yang sama dengan rentang waktu lain; dipakai saat token tahun fiskal sudah dihitung rentangnya. */
    public function withTimeRange(?TimeRange $timeRange): self
    {
        return new self(
            $this->dataset, $this->dimensions, $this->measures, $this->filters, $timeRange, $this->sort, $this->limit,
            $this->totals, $this->fillGaps, $this->formulas, $this->compare, $this->percentOfTotal,
        );
    }

    /**
     * Bentuk normal untuk kunci cache dan log: kunci urut, nilai daftar urut, tanpa nilai bawaan.
     *
     * Kunci area 13 hanya ditulis bila diisi, supaya query tanpa rumus dan perbandingan tetap berhash sama
     * dengan sebelumnya. Rentang tahun fiskal yang sudah dihitung ikut di `time_range.bounds`: token yang sama
     * berarti rentang berbeda bagi perusahaan berbeda, dan hasil satu perusahaan tidak boleh terbaca oleh yang
     * lain dari cache.
     *
     * @return array<string, mixed>
     */
    public function normalized(): array
    {
        $filters = $this->filters;
        ksort($filters);
        foreach ($filters as &$value) {
            if (is_array($value)) {
                sort($value, SORT_STRING);
            }
        }
        unset($value);

        $timeRange = $this->timeRange?->toArray();
        if ($timeRange !== null && $this->timeRange?->bounds !== null) {
            $timeRange['bounds'] = $this->timeRange->bounds;
        }

        $normalized = [
            'dataset' => $this->dataset,
            'dimensions' => array_map(fn (Dimension $d): array => $d->toArray(), $this->dimensions),
            'measures' => $this->measures,
            'filters' => $filters,
            'time_range' => $timeRange,
            'sort' => $this->sort,
            'limit' => $this->limit,
            'totals' => $this->totals,
            'fill_gaps' => $this->fillGaps,
        ];

        if ($this->formulas !== []) {
            $formulas = array_map(static fn (Formula $formula): array => $formula->toArray(), $this->formulas);
            usort($formulas, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
            $normalized['formulas'] = $formulas;
        }
        if ($this->compare !== null) {
            $normalized['compare'] = $this->compare->value;
        }
        if ($this->percentOfTotal !== []) {
            $percent = $this->percentOfTotal;
            sort($percent, SORT_STRING);
            $normalized['percent_of_total'] = $percent;
        }

        return $normalized;
    }
}
