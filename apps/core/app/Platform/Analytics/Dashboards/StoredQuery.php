<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Dashboards;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Query\Formula\Formula;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DatasetAccess;

/**
 * Query yang disimpan widget dan query tersimpan: diperiksa saat disimpan, disimpan dalam bentuk ringkas, dan
 * dibaca ulang terhadap dataset yang mungkin sudah berubah.
 *
 * - **Saat disimpan** ({@see self::validate()}) query melewati jalur yang sama dengan `RunQuery` sampai sebelum
 *   SQL: parser, normalisasi, dataset terpasang dan boleh dibaca penyimpannya, lalu validator dengan gerbang
 *   data pribadinya. Galatnya berpath di bawah `query.` supaya layar menunjuk isian yang salah.
 * - **Bentuknya ringkas** ({@see self::compact()}): sama dengan badan `POST api/v1/analytics/query` dan tipe
 *   `AnalyticsQuery` di layar, tanpa nilai bawaan yang ditulis `null`.
 * - **Saat dibaca** ({@see self::read()}) kunci yang diganti nama module sejak query disimpan dipetakan lewat
 *   `CompiledDataset::renamed()`, dan kunci yang sudah tidak ada dilaporkan dengan path-nya. Pemanggil
 *   menjadikannya status `field_removed`, bukan galat 500; query lama tidak pernah diam-diam dibuang kolomnya,
 *   karena angka tanpa satu saringan lebih besar dari yang diminta penyusunnya.
 *
 * Rumus (area 13) disimpan dengan teks tulisan penyusunnya. Kunci rumus di `measures` bukan measure dataset, jadi
 * tidak dilaporkan hilang; yang diperiksa adalah setiap `[kunci]` di dalam teks rumus, dan nama measure yang
 * diganti module ikut diganti di dalam teks itu.
 */
final class StoredQuery
{
    public function __construct(
        private readonly QueryParser $parser,
        private readonly QueryNormalizer $normalizer,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryValidator $validator,
    ) {}

    /**
     * @return array{0: CompiledDataset, 1: AnalyticsQuery}
     *
     * @throws AnalyticsQueryException dengan path berawalan `$path`
     */
    public function validate(AnalyticsPrincipal $principal, mixed $input, string $path = 'query'): array
    {
        if (! is_array($input)) {
            throw AnalyticsQueryException::invalidQuery($path, 'Susun analisisnya lebih dulu: pilih data dan nilai yang dihitung.');
        }

        try {
            $query = $this->normalizer->normalize($this->parser->parse($input));
            $dataset = $this->datasets->find($query->dataset) ?? throw AnalyticsQueryException::datasetUnknown();
            $this->access->authorize($principal, $dataset);
            $this->validator->validate($dataset, $query, $principal);
        } catch (AnalyticsQueryException $e) {
            throw new AnalyticsQueryException($e->errorCode, $e->getMessage(), $e->status, $path.($e->field === null ? '' : '.'.$e->field), $e, position: $e->position);
        }

        return [$dataset, $query];
    }

    /**
     * Bentuk simpan dan bentuk kirim ke layar: kunci hanya bila nilainya bukan bawaan.
     *
     * @return array<string, mixed>
     */
    public static function compact(AnalyticsQuery $query): array
    {
        $out = [
            'dataset' => $query->dataset,
            'dimensions' => array_map(
                static fn (Dimension $dimension): string|array => $dimension->granularity === null
                    ? $dimension->field
                    : ['field' => $dimension->field, 'granularity' => $dimension->granularity->value],
                $query->dimensions,
            ),
            'measures' => $query->measures,
            'filters' => $query->filters,
        ];
        if ($out['dimensions'] === []) {
            unset($out['dimensions']);
        }
        if ($out['filters'] === []) {
            unset($out['filters']);
        }
        if ($query->timeRange !== null) {
            $out['time_range'] = array_filter($query->timeRange->toArray(), static fn (?string $value): bool => $value !== null);
        }
        if ($query->sort !== []) {
            $out['sort'] = $query->sort;
        }
        if ($query->limit !== null) {
            $out['limit'] = $query->limit;
        }
        if ($query->totals) {
            $out['totals'] = true;
        }
        // Bawaan parser: celah diisi bila ada pengelompokan menurut waktu. Hanya penyimpangannya yang ditulis.
        $buckets = array_filter($query->dimensions, static fn (Dimension $dimension): bool => $dimension->granularity !== null) !== [];
        if ($buckets && ! $query->fillGaps) {
            $out['fill_gaps'] = false;
        }
        if ($query->compare !== null) {
            $out['compare'] = $query->compare->value;
        }
        if ($query->formulas !== []) {
            $out['formulas'] = array_map(static fn (Formula $formula): array => $formula->toCompact(), $query->formulas);
        }
        if ($query->percentOfTotal !== []) {
            $out['percent_of_total'] = $query->percentOfTotal;
        }

        return $out;
    }

    /**
     * Query tersimpan dibaca terhadap dataset saat ini: kunci yang diganti nama sejak `$storedVersion`
     * dipetakan, dan kunci yang tidak dikenal lagi dilaporkan per path. `map` adalah peta kunci lama => baru
     * yang berlaku, untuk memetakan `visual` widget dengan peta yang sama; `missing` adalah path => kunci yang
     * hilang.
     *
     * @param  array<string, mixed>  $stored
     * @return array{query: array<string, mixed>, map: array<string, string>, missing: array<string, string>}
     */
    public static function read(CompiledDataset $dataset, array $stored, ?int $storedVersion): array
    {
        $map = $storedVersion !== null && $storedVersion < $dataset->version ? $dataset->renamed() : [];
        $query = self::ordered(self::rename($stored, $map));

        $missing = [];
        foreach (self::fieldUses($query) as $path => $key) {
            if (! $dataset->hasField($key)) {
                $missing[$path] = $key;
            }
        }
        $formulas = [];
        foreach (self::listOf($query['formulas'] ?? null) as $i => $formula) {
            if (! is_array($formula)) {
                continue;
            }
            if (is_string($formula['key'] ?? null)) {
                $formulas[$formula['key']] = true;
            }
            foreach (self::formulaMeasures($formula['expression'] ?? null) as $key) {
                if (! $dataset->hasMeasure($key)) {
                    $missing["formulas.{$i}.expression"] = $key;
                }
            }
        }
        foreach (self::listOf($query['measures'] ?? null) as $i => $key) {
            if (is_string($key) && ! isset($formulas[$key]) && ! $dataset->hasMeasure($key)) {
                $missing["measures.{$i}"] = $key;
            }
        }

        return ['query' => $query, 'map' => $map, 'missing' => $missing];
    }

    /**
     * Kunci query dalam urutan bentuk query (`QueryParser::KEYS`). Kolom `jsonb` PostgreSQL menyimpan kunci objek
     * menurut panjang dan abjadnya sendiri, jadi urutan dikembalikan saat dibaca supaya layar menerima bentuk
     * yang sama dengan yang ia kirim.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function ordered(array $query): array
    {
        // Kunci yang dikenal lebih dulu menurut urutannya, lalu sisanya; nilainya selalu dari query.
        return array_replace(array_intersect_key(array_flip(QueryParser::KEYS), $query), $query);
    }

    /**
     * Mengganti kunci field dan measure lewat peta kunci lama => baru, di setiap tempat query menyebut kunci.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    public static function rename(array $query, array $map): array
    {
        if ($map === []) {
            return $query;
        }
        $key = static fn (mixed $value): mixed => is_string($value) ? ($map[$value] ?? $value) : $value;

        if (is_array($query['dimensions'] ?? null)) {
            $query['dimensions'] = array_map(static function (mixed $dimension) use ($key): mixed {
                if (is_array($dimension) && array_key_exists('field', $dimension)) {
                    $dimension['field'] = $key($dimension['field']);

                    return $dimension;
                }

                return $key($dimension);
            }, $query['dimensions']);
        }
        if (is_array($query['measures'] ?? null)) {
            $query['measures'] = array_map($key, $query['measures']);
        }
        if (is_array($query['filters'] ?? null)) {
            $filters = [];
            foreach ($query['filters'] as $field => $value) {
                $filters[(string) $key($field)] = $value;
            }
            $query['filters'] = $filters;
        }
        if (is_array($query['time_range'] ?? null) && array_key_exists('field', $query['time_range'])) {
            $query['time_range']['field'] = $key($query['time_range']['field']);
        }
        if (is_array($query['sort'] ?? null)) {
            $query['sort'] = array_map(static function (mixed $sort) use ($key): mixed {
                if (is_array($sort) && array_key_exists('key', $sort)) {
                    $sort['key'] = $key($sort['key']);
                }

                return $sort;
            }, $query['sort']);
        }
        if (is_array($query['percent_of_total'] ?? null)) {
            $query['percent_of_total'] = array_map($key, $query['percent_of_total']);
        }
        if (is_array($query['formulas'] ?? null)) {
            $query['formulas'] = array_map(static function (mixed $formula) use ($map): mixed {
                if (is_array($formula) && is_string($formula['expression'] ?? null)) {
                    $formula['expression'] = Formula::renameMeasures($formula['expression'], $map);
                }

                return $formula;
            }, $query['formulas']);
        }

        return $query;
    }

    /**
     * Kunci field yang disebut query per path: pengelompok, saringan, dan kolom rentang waktu. Urutan memakai
     * kunci terpilih, jadi tidak perlu diperiksa sendiri.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    private static function fieldUses(array $query): array
    {
        $uses = [];
        foreach (self::listOf($query['dimensions'] ?? null) as $i => $dimension) {
            $field = is_array($dimension) ? ($dimension['field'] ?? null) : $dimension;
            if (is_string($field)) {
                $uses["dimensions.{$i}"] = $field;
            }
        }
        if (is_array($query['filters'] ?? null)) {
            foreach (array_keys($query['filters']) as $field) {
                $uses["filters.{$field}"] = (string) $field;
            }
        }
        $timeField = is_array($query['time_range'] ?? null) ? ($query['time_range']['field'] ?? null) : null;
        if (is_string($timeField)) {
            $uses['time_range.field'] = $timeField;
        }

        return $uses;
    }

    /**
     * Kunci measure di dalam teks rumus tersimpan, yaitu setiap `[kunci]`. Teks yang rusak tetap ditolak dengan
     * posisinya saat query widget dibaca ulang untuk dihitung.
     *
     * @return list<string>
     */
    private static function formulaMeasures(mixed $expression): array
    {
        if (! is_string($expression)) {
            return [];
        }
        preg_match_all('/\[([a-z][a-z0-9_]{0,63})\]/', $expression, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
