<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Query\Formula\Formula;
use App\Platform\Analytics\Query\Formula\InvalidFormula;
use App\Platform\Analytics\Query\Formula\Parser as FormulaParser;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\FieldFilterExpression;

/**
 * Membaca JSON query analitik menjadi {@see AnalyticsQuery} dan menolak bentuk yang salah dengan galat
 * berpath (`dimensions.1.granularity`, `filters.nama`) dan pesan bahasa sehari-hari.
 *
 * Yang diperiksa di sini hanya bentuknya: tipe nilai, kunci yang dikenal, panjang. Apakah kunci field
 * dan measure dikenal dataset, dan apakah jumlahnya dalam batas, diperiksa {@see QueryValidator} sesudah
 * dataset dan hak pembacanya pasti. Dua JSON yang setara baru menjadi bentuk yang sama sesudah
 * {@see QueryNormalizer}; parser membaca apa adanya.
 *
 * Kunci yang dikenal sama dengan `resources/schemas/analytics-query.schema.json`, dan satu test menjaganya.
 *
 * Kunci fase 2 (area 13): `formulas` dibaca teks rumusnya di sini lewat {@see FormulaParser} — fungsi di luar
 * daftar tertutup, kurung tanpa pasangan, dan karakter asing ditolak sebelum ada SQL, dengan galat
 * `analytics.invalid_formula` yang menyebut karakter tempatnya; `compare` dan `percent_of_total` dibaca bentuknya.
 * Apakah measure yang dirujuk rumus dikenal dataset diperiksa {@see QueryValidator}.
 */
final class QueryParser
{
    /** @var list<string> */
    public const KEYS = ['dataset', 'dimensions', 'measures', 'filters', 'time_range', 'sort', 'limit', 'totals', 'fill_gaps', 'compare', 'formulas', 'percent_of_total'];

    /** @var list<string> */
    public const FORMULA_KEYS = ['key', 'caption', 'expression', 'format'];

    /** @var list<string> */
    public const DIMENSION_KEYS = ['field', 'granularity'];

    /** @var list<string> */
    public const TIME_RANGE_KEYS = ['field', 'range'];

    /** @var list<string> */
    public const SORT_KEYS = ['key', 'direction'];

    private const MAX_KEY_LENGTH = 120;

    /**
     * Kunci rumus: bentuk kunci dataset (huruf kecil, angka, garis bawah, diawali huruf), tanpa `__` yang dipakai
     * kolom pendamping (`__label`, `__previous`).
     */
    private const FORMULA_KEY = '/^[a-z](?!.*__)[a-z0-9_]{0,63}$/';

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws AnalyticsQueryException
     */
    public function parse(array $input): AnalyticsQuery
    {
        $this->assertKnownKeys($input, self::KEYS, '');

        $dimensions = $this->dimensions($input['dimensions'] ?? []);
        $buckets = array_filter($dimensions, static fn (Dimension $dimension): bool => $dimension->granularity !== null) !== [];

        return new AnalyticsQuery(
            dataset: $this->dataset($input['dataset'] ?? null),
            dimensions: $dimensions,
            measures: $this->measures($input['measures'] ?? null),
            filters: $this->filters($input['filters'] ?? []),
            timeRange: $this->timeRange($input['time_range'] ?? null),
            sort: $this->sort($input['sort'] ?? []),
            limit: $this->limit($input['limit'] ?? null),
            totals: $this->flag($input['totals'] ?? null, 'totals', 'Total harus berupa true atau false.') ?? false,
            // Bawaannya mengikuti ada tidaknya pengelompokan menurut waktu: celah hanya ada di deret waktu.
            fillGaps: $this->flag($input['fill_gaps'] ?? null, 'fill_gaps', 'Isian celah waktu harus berupa true atau false.') ?? $buckets,
            formulas: $this->formulas($input['formulas'] ?? null),
            compare: $this->compare($input['compare'] ?? null),
            percentOfTotal: $this->percentOfTotal($input['percent_of_total'] ?? null),
        );
    }

    private function dataset(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > self::MAX_KEY_LENGTH) {
            throw AnalyticsQueryException::invalidQuery('dataset', 'Pilih data yang akan dianalisis.');
        }

        return $value;
    }

    /** @return list<Dimension> */
    private function dimensions(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw AnalyticsQueryException::invalidQuery('dimensions', 'Pengelompokan harus berupa daftar kolom.');
        }

        $out = [];
        foreach ($value as $i => $dimension) {
            $path = "dimensions.{$i}";

            if (! is_array($dimension)) {
                $out[] = new Dimension($this->key($dimension, $path, 'Pengelompokan harus berupa nama kolom.'));

                continue;
            }

            if (! $this->isObject($dimension)) {
                throw AnalyticsQueryException::invalidQuery($path, 'Pengelompokan harus berupa nama kolom, atau kolom beserta ukuran waktunya.');
            }
            $this->assertKnownKeys($dimension, self::DIMENSION_KEYS, $path);

            $granularity = $dimension['granularity'] ?? null;
            $out[] = new Dimension(
                $this->key($dimension['field'] ?? null, "{$path}.field", 'Pengelompokan harus menyebut nama kolom.'),
                $granularity === null ? null : $this->granularity($granularity, "{$path}.granularity"),
            );
        }

        return $out;
    }

    private function granularity(mixed $value, string $path): TimeGranularity
    {
        $granularity = is_string($value) ? TimeGranularity::tryFrom($value) : null;

        return $granularity ?? throw AnalyticsQueryException::invalidQuery(
            $path,
            'Ukuran waktu harus salah satu dari: '.implode(', ', array_map(static fn (TimeGranularity $case): string => $case->value, TimeGranularity::cases())).'.',
        );
    }

    /** @return list<string> */
    private function measures(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw AnalyticsQueryException::invalidQuery('measures', 'Pilih sedikitnya satu nilai yang dihitung.');
        }

        $out = [];
        foreach ($value as $i => $measure) {
            $out[] = $this->key($measure, "measures.{$i}", 'Nilai yang dihitung harus berupa nama nilai.');
        }

        return $out;
    }

    /** @return array<string, string|list<string>> */
    private function filters(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        // `{}` dari JSON terbaca sebagai daftar kosong; itu sah dan berarti tanpa saringan.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw AnalyticsQueryException::invalidQuery('filters', 'Saringan harus berupa pasangan kolom dan isiannya.');
        }

        $out = [];
        foreach ($value as $key => $filter) {
            $key = $this->key($key, 'filters', 'Saringan harus berupa pasangan kolom dan isiannya.');
            // Isian yang dikosongkan di layar tiba sebagai `null`, karena `ConvertEmptyStringsToNull` juga
            // membersihkan badan JSON. Itu sama dengan teks kosong, yaitu tanpa saringan.
            if ($filter === null || is_string($filter)) {
                $out[$key] = $filter ?? '';

                continue;
            }
            if (! is_array($filter) || ! array_is_list($filter)) {
                throw AnalyticsQueryException::invalidQuery("filters.{$key}", 'Isian saringan harus berupa teks atau daftar pilihan.');
            }
            $values = [];
            foreach ($filter as $item) {
                if ($item !== null && ! is_string($item)) {
                    throw AnalyticsQueryException::invalidQuery("filters.{$key}", 'Daftar pilihan saringan harus berisi teks.');
                }
                $values[] = $item ?? '';
            }
            $out[$key] = $values;
        }

        return $out;
    }

    private function timeRange(mixed $value): ?TimeRange
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! $this->isObject($value)) {
            throw AnalyticsQueryException::invalidQuery('time_range', 'Rentang waktu harus berisi "range", dan "field" bila perlu.');
        }
        $this->assertKnownKeys($value, self::TIME_RANGE_KEYS, 'time_range');

        $range = $value['range'] ?? null;
        if (! is_string($range) || trim($range) === '') {
            throw AnalyticsQueryException::invalidQuery('time_range.range', 'Isi rentang waktu, misalnya @this_month atau 01/01/2026..31/03/2026.');
        }
        if (mb_strlen($range) > FieldFilterExpression::MAX_LENGTH) {
            throw AnalyticsQueryException::invalidQuery('time_range.range', 'Rentang waktu terlalu panjang, maksimal '.FieldFilterExpression::MAX_LENGTH.' karakter.');
        }

        $field = $value['field'] ?? null;

        return new TimeRange($range, $field === null ? null : $this->key($field, 'time_range.field', 'Kolom rentang waktu harus berupa nama kolom.'));
    }

    /** @return list<array{key: string, direction: 'asc'|'desc'}> */
    private function sort(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw AnalyticsQueryException::invalidQuery('sort', 'Urutan harus berupa daftar.');
        }

        $out = [];
        foreach ($value as $i => $item) {
            $path = "sort.{$i}";
            if (! is_array($item) || ! $this->isObject($item)) {
                throw AnalyticsQueryException::invalidQuery($path, 'Urutan harus berisi "key" dan "direction".');
            }
            $this->assertKnownKeys($item, self::SORT_KEYS, $path);

            $direction = $item['direction'] ?? null;
            if ($direction !== 'asc' && $direction !== 'desc') {
                throw AnalyticsQueryException::invalidQuery("{$path}.direction", 'Arah urutan harus asc atau desc.');
            }

            $out[] = [
                'key' => $this->key($item['key'] ?? null, "{$path}.key", 'Urutan harus menyebut nama kolom atau nilai.'),
                'direction' => $direction,
            ];
        }

        return $out;
    }

    /** @return list<Formula> */
    private function formulas(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw AnalyticsQueryException::invalidQuery('formulas', 'Rumus harus berupa daftar.');
        }

        $out = [];
        $keys = [];
        foreach ($value as $i => $item) {
            $path = "formulas.{$i}";
            if (! is_array($item) || ! $this->isObject($item)) {
                throw AnalyticsQueryException::invalidQuery($path, 'Rumus harus berisi "key" dan "expression".');
            }
            $this->assertKnownKeys($item, self::FORMULA_KEYS, $path);

            $key = $item['key'] ?? null;
            if (! is_string($key) || preg_match(self::FORMULA_KEY, $key) !== 1) {
                throw AnalyticsQueryException::invalidQuery("{$path}.key", 'Nama rumus hanya boleh huruf kecil, angka, dan garis bawah (tidak dua berturut-turut), diawali huruf, misalnya persen_dilepas.');
            }
            if (isset($keys[$key])) {
                throw AnalyticsQueryException::invalidQuery("{$path}.key", 'Nama rumus "'.$key.'" dipakai lebih dari sekali.');
            }
            $keys[$key] = true;

            $caption = $item['caption'] ?? null;
            if ($caption !== null && (! is_string($caption) || trim($caption) === '' || mb_strlen($caption) > self::MAX_KEY_LENGTH)) {
                throw AnalyticsQueryException::invalidQuery("{$path}.caption", 'Nama tampilan rumus paling panjang '.self::MAX_KEY_LENGTH.' karakter.');
            }

            $format = $item['format'] ?? null;
            if ($format !== null) {
                $format = is_string($format) ? MeasureFormat::tryFrom($format) : null;
                if ($format === null) {
                    throw AnalyticsQueryException::invalidQuery("{$path}.format", 'Format rumus harus salah satu dari: '.implode(', ', array_map(static fn (MeasureFormat $case): string => $case->value, MeasureFormat::cases())).'.');
                }
            }

            $expression = $item['expression'] ?? null;
            if (! is_string($expression)) {
                throw AnalyticsQueryException::invalidFormula("{$path}.expression", 'Isi rumusnya, misalnya BAGI([disposed]; [count]) * 100.', 1);
            }
            try {
                $node = FormulaParser::parse($expression);
            } catch (InvalidFormula $e) {
                throw AnalyticsQueryException::invalidFormula("{$path}.expression", $e->getMessage(), $e->position, $e);
            }

            $out[] = new Formula($key, $expression, $node, $caption === null ? null : trim($caption), $format);
        }

        return $out;
    }

    private function compare(mixed $value): ?CompareMode
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? CompareMode::tryFrom($value) : null) ?? throw AnalyticsQueryException::invalidQuery(
            'compare',
            'Perbandingan harus salah satu dari: '.implode(', ', array_map(static fn (CompareMode $case): string => $case->value, CompareMode::cases())).'.',
        );
    }

    /** @return list<string> */
    private function percentOfTotal(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw AnalyticsQueryException::invalidQuery('percent_of_total', 'Persen terhadap total harus berupa daftar nilai yang dihitung.');
        }

        $out = [];
        foreach ($value as $i => $key) {
            $out[] = $this->key($key, "percent_of_total.{$i}", 'Persen terhadap total harus menyebut nama nilai yang dihitung.');
        }

        return $out;
    }

    private function limit(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw AnalyticsQueryException::invalidQuery('limit', 'Batas baris harus bilangan bulat mulai dari 1.');
        }

        return $value;
    }

    private function flag(mixed $value, string $path, string $message): ?bool
    {
        if ($value === null) {
            return null;
        }

        return is_bool($value) ? $value : throw AnalyticsQueryException::invalidQuery($path, $message);
    }

    private function key(mixed $value, string $path, string $message): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_KEY_LENGTH) {
            throw AnalyticsQueryException::invalidQuery($path, $message);
        }

        return $value;
    }

    /**
     * `{}` dari JSON terbaca sebagai daftar kosong, jadi objek kosong juga objek.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function isObject(array $value): bool
    {
        return $value === [] || ! array_is_list($value);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $known
     */
    private function assertKnownKeys(array $input, array $known, string $path): void
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $known, true)) {
                throw AnalyticsQueryException::invalidQuery($path === '' ? (string) $key : $path.'.'.$key, 'Bagian "'.$key.'" tidak dikenal.');
            }
        }
    }
}
