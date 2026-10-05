<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Query\Formula\Formula;

/**
 * Menyatukan query yang setara menjadi satu bentuk: dua JSON yang hanya berbeda urutan kunci, spasi di
 * ujung isian, pilihan yang diulang, atau saringan kosong menghasilkan {@see AnalyticsQuery} yang sama,
 * sehingga hash query (`meta.query_hash`) dan kunci cache area 9 sama.
 *
 * Yang **tidak** disatukan, karena urutannya mengubah hasil: urutan `dimensions` dan `measures` (menentukan
 * urutan kolom hasil), dan urutan `sort` (urutan utama dan pemutus seri). Daftar pilihan di `filters`
 * dibaca sebagai ATAU, jadi urutannya tidak berarti apa-apa dan diurutkan.
 *
 * Rumus (area 13) diurutkan menurut kuncinya dan daftar persen terhadap total diurutkan tanpa ganda: keduanya
 * tidak menentukan urutan kolom — urutan itu milik `measures`. Teks rumus tidak diubah, karena teks itulah yang
 * disimpan widget dan dikembalikan ke editor.
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
            formulas: $this->formulas($query->formulas),
            compare: $query->compare,
            percentOfTotal: $this->keys($query->percentOfTotal),
        );
    }

    /**
     * @param  list<Formula>  $formulas
     * @return list<Formula>
     */
    private function formulas(array $formulas): array
    {
        usort($formulas, static fn (Formula $a, Formula $b): int => strcmp($a->key, $b->key));

        return $formulas;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function keys(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
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
