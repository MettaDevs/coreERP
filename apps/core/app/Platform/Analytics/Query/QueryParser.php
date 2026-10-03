<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Membaca JSON query analitik menjadi {@see AnalyticsQuery} dan menolak bentuk yang salah dengan galat
 * berpath (`dimensions.1`, `filters.nama`) dan pesan bahasa sehari-hari.
 *
 * Yang diperiksa di sini hanya bentuknya. Apakah kunci field dan measure dikenal dataset diperiksa
 * {@see QueryValidator}, sesudah dataset dan hak pembacanya pasti.
 *
 * Kerangka berjalan (area 0) membaca `dataset`, `dimensions` berupa kunci field, `measures`, `filters`,
 * dan `limit`. Kunci lain dari bentuk query lengkap — `time_range`, `sort`, `totals`, `fill_gaps`, dan
 * dimensi berember waktu — ditolak, bukan diabaikan: query yang diam-diam mengabaikan rentang waktu
 * memulangkan angka yang lebih besar dari yang diminta. Area 2 menambahkannya.
 */
final class QueryParser
{
    /** @var list<string> */
    private const KEYS = ['dataset', 'dimensions', 'measures', 'filters', 'limit'];

    private const MAX_KEY_LENGTH = 120;

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws AnalyticsQueryException
     */
    public function parse(array $input): AnalyticsQuery
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw AnalyticsQueryException::invalidQuery((string) $key, 'Bagian "'.$key.'" tidak dikenal atau belum didukung.');
            }
        }

        return new AnalyticsQuery(
            dataset: $this->dataset($input['dataset'] ?? null),
            dimensions: $this->dimensions($input['dimensions'] ?? []),
            measures: $this->measures($input['measures'] ?? null),
            filters: $this->filters($input['filters'] ?? []),
            timeRange: null,
            sort: [],
            limit: $this->limit($input['limit'] ?? null),
            totals: false,
            fillGaps: false,
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
        if (! is_array($value) || ! array_is_list($value)) {
            throw AnalyticsQueryException::invalidQuery('dimensions', 'Pengelompokan harus berupa daftar kolom.');
        }

        $out = [];
        foreach ($value as $i => $dimension) {
            if (is_array($dimension)) {
                throw AnalyticsQueryException::invalidQuery("dimensions.{$i}", 'Pengelompokan menurut waktu belum didukung.');
            }
            $out[] = new Dimension($this->key($dimension, "dimensions.{$i}", 'Pengelompokan harus berupa nama kolom.'));
        }

        return $out;
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
        // `{}` dari JSON terbaca sebagai daftar kosong; itu sah dan berarti tanpa saringan.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw AnalyticsQueryException::invalidQuery('filters', 'Saringan harus berupa pasangan kolom dan isiannya.');
        }

        $out = [];
        foreach ($value as $key => $filter) {
            $key = $this->key($key, 'filters', 'Saringan harus berupa pasangan kolom dan isiannya.');
            if (is_string($filter)) {
                $out[$key] = $filter;

                continue;
            }
            if (! is_array($filter) || ! array_is_list($filter)) {
                throw AnalyticsQueryException::invalidQuery("filters.{$key}", 'Isian saringan harus berupa teks atau daftar pilihan.');
            }
            $values = [];
            foreach ($filter as $item) {
                if (! is_string($item)) {
                    throw AnalyticsQueryException::invalidQuery("filters.{$key}", 'Daftar pilihan saringan harus berisi teks.');
                }
                $values[] = $item;
            }
            $out[$key] = $values;
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

    private function key(mixed $value, string $field, string $message): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_KEY_LENGTH) {
            throw AnalyticsQueryException::invalidQuery($field, $message);
        }

        return $value;
    }
}
