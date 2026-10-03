<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Menyatukan query yang setara menjadi satu bentuk: dua JSON yang hanya berbeda urutan kunci, spasi di
 * ujung isian, pilihan yang diulang, atau saringan kosong menghasilkan {@see AnalyticsQuery} yang sama,
 * sehingga hash query (`meta.query_hash`) dan kunci cache area 9 sama.
 *
 * Yang **tidak** disatukan, karena urutannya mengubah hasil: urutan `dimensions` dan `measures` (menentukan
 * urutan kolom hasil), dan urutan `sort` (urutan utama dan pemutus seri). Daftar pilihan di `filters`
 * dibaca sebagai ATAU, jadi urutannya tidak berarti apa-apa dan diurutkan.
 *
 * Normalisasi tidak memeriksa apa pun: kolom yang tidak dikenal tetap tidak dikenal. Pemeriksaan milik
 * {@see QueryValidator}, yang berjalan sesudah ini, jadi saringan kosong pada kolom yang salah ketik
 * tidak ditolak: saringan kosong memang tidak menyaring apa pun, sama seperti di
 * `FieldFilterExpression`.
 */
final class QueryNormalizer
{
    public function normalize(AnalyticsQuery $query): AnalyticsQuery
    {
        $hasBucket = array_filter($query->dimensions, static fn (Dimension $dimension): bool => $dimension->granularity !== null) !== [];

        return new AnalyticsQuery(
            dataset: trim($query->dataset),
            dimensions: $query->dimensions,
            measures: $query->measures,
            filters: $this->filters($query->filters),
            timeRange: $query->timeRange === null ? null : new TimeRange(trim($query->timeRange->range), $query->timeRange->field),
            sort: $query->sort,
            limit: $query->limit,
            totals: $query->totals,
            // Celah hanya ada di deret waktu; tanpa pengelompokan menurut waktu isian ini tidak berarti apa-apa.
            fillGaps: $query->fillGaps && $hasBucket,
        );
    }

    /**
     * @param  array<string, string|list<string>>  $filters
     * @return array<string, string|list<string>>
     */
    private function filters(array $filters): array
    {
        $out = [];

        foreach ($filters as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    $out[$key] = $value;
                }

                continue;
            }

            $items = array_values(array_unique(array_filter(
                array_map('trim', $value),
                static fn (string $item): bool => $item !== '',
            )));
            if ($items !== []) {
                sort($items, SORT_STRING);
                $out[$key] = $items;
            }
        }

        ksort($out);

        return $out;
    }
}
