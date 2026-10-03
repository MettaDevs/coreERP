<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

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
 * `compare` dan `formulas` milik fase 2 dan ditolak sebagai belum tersedia: query yang diam-diam
 * mengabaikan perbandingan atau rumus memulangkan angka yang berbeda dari yang diminta.
 */
final class QueryParser
{
    /** @var list<string> */
    public const KEYS = ['dataset', 'dimensions', 'measures', 'filters', 'time_range', 'sort', 'limit', 'totals', 'fill_gaps'];

    /** Kunci fase 2 yang sudah ada di bentuk query tetapi belum dibaca engine. */
    public const FUTURE_KEYS = ['compare', 'formulas'];

    /** @var list<string> */
    public const DIMENSION_KEYS = ['field', 'granularity'];

    /** @var list<string> */
    public const TIME_RANGE_KEYS = ['field', 'range'];

    /** @var list<string> */
    public const SORT_KEYS = ['key', 'direction'];

    private const MAX_KEY_LENGTH = 120;

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws AnalyticsQueryException
     */
    public function parse(array $input): AnalyticsQuery
    {
        foreach (self::FUTURE_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                throw AnalyticsQueryException::invalidQuery($key, 'Bagian "'.$key.'" belum tersedia.');
            }
        }
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
