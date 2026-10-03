<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;

/**
 * Memeriksa query terhadap dataset dan principal sebelum ada SQL apa pun. Urutannya: batas jumlah, setiap
 * kunci dikenal dataset, tidak ada kunci yang dipilih dua kali, ember waktu hanya pada kolom waktu, rentang
 * waktu bermakna, `sort` hanya pada kunci yang dipilih, `limit` dalam batas principal, lalu gerbang data
 * pribadi ({@see FieldUseGate}).
 *
 * Saringan pada kolom yang tidak dikenal **ditolak, bukan diabaikan**: saringan yang diabaikan diam-diam
 * memulangkan angka yang lebih besar dari yang diminta pengguna.
 *
 * Batas jumlah dibaca dari `config/analytics.php` (`limits.dimensions`, `limits.measures`, `limits.filters`,
 * `limits.sort`). Isi saringan dan ekspresi tanggal pada rentang waktu **tidak** dibaca di sini: bentuknya
 * milik `FieldFilterExpression`, yang menolaknya saat compile dengan path yang sama, juga sebelum ada
 * query ke database.
 *
 * Gerbang data pribadi opsional sampai area 4 mengikatnya (lihat {@see FieldUseGate}); parameter ini
 * menjadi wajib saat itu, supaya tidak ada jalur yang melewatinya.
 */
final class QueryValidator
{
    public function __construct(
        private readonly ?FieldUseGate $fieldGate = null,
    ) {}

    /** @throws AnalyticsQueryException */
    public function validate(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): void
    {
        /** @var array<string, string> $uses path bagian query → kunci field dataset, untuk gerbang data pribadi */
        $uses = [];
        /** @var array<string, true> $chosen kunci yang menjadi kolom hasil, dan karenanya tidak boleh ganda */
        $chosen = [];

        $this->assertWithin($query->dimensions, 'limits.dimensions', 4, 'dimensions', 'Terlalu banyak kolom pengelompokan. Maksimal %d.');
        foreach ($query->dimensions as $i => $dimension) {
            if (! $dataset->hasField($dimension->field)) {
                throw AnalyticsQueryException::fieldUnknown("dimensions.{$i}", $dimension->field);
            }
            if (isset($chosen[$dimension->field])) {
                throw AnalyticsQueryException::invalidQuery("dimensions.{$i}", 'Kolom "'.$dimension->field.'" dipilih lebih dari sekali.');
            }
            if ($dimension->granularity !== null && ! in_array($dimension->field, $dataset->times(), true)) {
                throw AnalyticsQueryException::invalidQuery("dimensions.{$i}.granularity", 'Kolom "'.$dimension->field.'" bukan kolom tanggal, jadi tidak bisa dikelompokkan per hari, minggu, bulan, kuartal, atau tahun.');
            }
            $chosen[$dimension->field] = true;
            $uses["dimensions.{$i}"] = $dimension->field;
        }

        $this->assertWithin($query->measures, 'limits.measures', 12, 'measures', 'Terlalu banyak nilai yang dihitung. Maksimal %d.');
        foreach ($query->measures as $i => $measure) {
            if (! $dataset->hasMeasure($measure)) {
                throw AnalyticsQueryException::measureUnknown("measures.{$i}", $measure);
            }
            // Hasil memakai kunci dataset sebagai nama kolom, jadi satu kunci hanya boleh muncul sekali
            // di antara pengelompok dan nilai.
            if (isset($chosen[$measure])) {
                throw AnalyticsQueryException::invalidQuery("measures.{$i}", 'Nilai "'.$measure.'" dipilih lebih dari sekali.');
            }
            $chosen[$measure] = true;
        }

        $this->assertWithin($query->filters, 'limits.filters', 20, 'filters', 'Terlalu banyak saringan. Maksimal %d.');
        foreach (array_keys($query->filters) as $key) {
            if (! $dataset->hasField($key)) {
                throw AnalyticsQueryException::fieldUnknown("filters.{$key}", $key);
            }
            $uses["filters.{$key}"] = $key;
        }

        if ($query->timeRange !== null) {
            $uses['time_range.field'] = $this->timeRangeField($dataset, $query->timeRange);
        }

        $this->assertWithin($query->sort, 'limits.sort', 3, 'sort', 'Terlalu banyak urutan. Maksimal %d.');
        $sorted = [];
        foreach ($query->sort as $i => $sort) {
            if (! isset($chosen[$sort['key']])) {
                throw AnalyticsQueryException::invalidQuery("sort.{$i}.key", 'Urutan hanya bisa memakai kolom atau nilai yang dipilih, dan "'.$sort['key'].'" tidak ada di pilihan.');
            }
            if (isset($sorted[$sort['key']])) {
                throw AnalyticsQueryException::invalidQuery("sort.{$i}.key", '"'.$sort['key'].'" diurutkan lebih dari sekali.');
            }
            $sorted[$sort['key']] = true;
        }

        if ($query->limit !== null && $query->limit > $principal->rowLimit()) {
            throw AnalyticsQueryException::limitExceeded('limit', 'Batas baris paling banyak '.$principal->rowLimit().'.');
        }

        // Terakhir: gerbang hanya melihat kolom yang sudah terbukti ada, dan pengguna tanpa hak tidak
        // belajar dari pesan galat sebelumnya kolom mana yang ada.
        if ($uses !== []) {
            $this->fieldGate?->assertUsable($dataset, $principal, $uses);
        }
    }

    /**
     * Kolom yang dipakai rentang waktu: yang disebut query, atau kolom waktu utama dataset. Harus kolom
     * waktu yang dinyatakan dataset, dan token yang diawali `@` harus salah satu token yang dikenal.
     *
     * @throws AnalyticsQueryException
     */
    private function timeRangeField(CompiledDataset $dataset, TimeRange $range): string
    {
        $field = $range->field ?? $dataset->defaultTime();

        if ($field === null) {
            throw AnalyticsQueryException::invalidQuery('time_range.field', 'Data ini tidak punya kolom tanggal utama. Sebutkan kolom tanggal untuk rentang waktu.');
        }
        if (! $dataset->hasField($field)) {
            throw AnalyticsQueryException::fieldUnknown('time_range.field', $field);
        }
        if (! in_array($field, $dataset->times(), true)) {
            throw AnalyticsQueryException::invalidQuery('time_range.field', 'Kolom "'.$field.'" bukan kolom tanggal, jadi tidak bisa dipakai untuk rentang waktu.');
        }
        if (RelativeRange::isToken($range->range) && ! RelativeRange::known($range->range)) {
            throw RelativeRange::unknown($range->range);
        }

        return $field;
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  non-empty-string  $configKey  kunci di bawah `analytics.`
     * @param  non-empty-string  $path
     * @param  non-empty-string  $message  memuat satu `%d` untuk batasnya
     *
     * @throws AnalyticsQueryException
     */
    private function assertWithin(array $items, string $configKey, int $default, string $path, string $message): void
    {
        $max = config()->integer('analytics.'.$configKey, $default);

        if (count($items) > $max) {
            throw AnalyticsQueryException::limitExceeded($path, sprintf($message, $max));
        }
    }
}
