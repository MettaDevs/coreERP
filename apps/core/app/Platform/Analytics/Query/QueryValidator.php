<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\Formula\Formula;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;

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
 * Gerbang data pribadi wajib (area 4, diikat ke `Security\PersonalDataGate` lewat {@see FieldUseGate}),
 * supaya tidak ada jalur yang melewatinya.
 *
 * Area 13 menambah tiga pemeriksaan. **Rumus**: paling banyak `limits.formulas`, kuncinya tidak bentrok dengan
 * kolom atau nilai dataset dan dipilih di `measures`, setiap `[kunci]` di dalamnya measure dataset — bukan rumus
 * lain — dan measure-measure itu tidak mencampur dua kolom mata uang atau dua kolom satuan (KA-22). Kolom yang
 * dibaca measure di dalam rumus ikut dilaporkan ke gerbang data pribadi. **Perbandingan periode** butuh rentang
 * waktu yang jelas awal dan akhirnya, dan tanggal yang dikelompokkan harus memakai ukuran waktu. **Persen terhadap
 * total** hanya untuk nilai yang dipilih.
 */
final class QueryValidator
{
    public function __construct(
        private readonly FieldUseGate $fieldGate,
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
        $formulas = [];
        foreach ($query->formulas as $formula) {
            $formulas[$formula->key] = $formula;
        }
        foreach ($query->measures as $i => $measure) {
            if (isset($formulas[$measure])) {
                if (isset($chosen[$measure])) {
                    throw AnalyticsQueryException::invalidQuery("measures.{$i}", 'Nilai "'.$measure.'" dipilih lebih dari sekali.');
                }
                $chosen[$measure] = true;

                continue;
            }
            if (! $dataset->hasMeasure($measure)) {
                throw AnalyticsQueryException::measureUnknown("measures.{$i}", $measure);
            }
            // Hasil memakai kunci dataset sebagai nama kolom, jadi satu kunci hanya boleh muncul sekali
            // di antara pengelompok dan nilai.
            if (isset($chosen[$measure])) {
                throw AnalyticsQueryException::invalidQuery("measures.{$i}", 'Nilai "'.$measure.'" dipilih lebih dari sekali.');
            }
            $chosen[$measure] = true;
            $uses = [...$uses, ...$this->measureUses($dataset, $measure, "measures.{$i}")];
        }

        $this->assertWithin($query->formulas, 'limits.formulas', 5, 'formulas', 'Terlalu banyak rumus. Maksimal %d.');
        foreach ($query->formulas as $i => $formula) {
            $uses = [...$uses, ...$this->formula($dataset, $query, $formula, "formulas.{$i}", $formulas)];
        }

        foreach ($query->percentOfTotal as $i => $key) {
            if (! in_array($key, $query->measures, true)) {
                throw AnalyticsQueryException::invalidQuery("percent_of_total.{$i}", 'Persen terhadap total hanya untuk nilai yang dihitung, dan "'.$key.'" tidak ada di pilihan.');
            }
            if (! isset($formulas[$key]) && ! $dataset->isNumericMeasure($key)) {
                throw AnalyticsQueryException::invalidQuery("percent_of_total.{$i}", 'Nilai "'.$key.'" bukan angka, jadi tidak punya persen terhadap total.');
            }
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

        if ($query->compare !== null) {
            $this->comparable($dataset, $query, $principal);
            $this->assertNoComparisonKeyCollisions($query, $chosen);
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
            $this->fieldGate->assertUsable($dataset, $principal, $uses);
        }
    }

    /**
     * Field yang dibaca satu measure dataset, untuk gerbang data pribadi: kolom bahannya bila ia field dataset, dan
     * setiap field saringan tetapnya. Aturannya sama dengan katalog (`PersonalDataGate::visibleMeasures()`), supaya
     * measure yang disembunyikan juga ditolak — juga bila ia dipakai lewat rumus.
     *
     * @return array<string, string> path => kunci field
     */
    private function measureUses(CompiledDataset $dataset, string $measure, string $path): array
    {
        $uses = [];
        $definition = $dataset->measure($measure);
        if ($definition->field !== null && $dataset->hasField($definition->field)) {
            $uses[$path] = $definition->field;
        }
        foreach (array_keys($definition->where) as $key) {
            if ($dataset->hasField($key)) {
                $uses["{$path}.where.{$key}"] = $key;
            }
        }

        return $uses;
    }

    /**
     * Kolom perbandingan memakai akhiran tetap yang tidak boleh menimpa nilai atau pengelompokan terpilih.
     *
     * @param  array<string, true>  $chosen
     */
    private function assertNoComparisonKeyCollisions(AnalyticsQuery $query, array $chosen): void
    {
        foreach ($query->measures as $i => $key) {
            foreach (['__previous', '__change', '__change_pct'] as $suffix) {
                if (isset($chosen[$key.$suffix])) {
                    throw AnalyticsQueryException::invalidQuery("measures.{$i}", 'Nilai yang dipilih bertabrakan dengan kolom perbandingan. Pilih nilai lain.');
                }
            }
        }
    }

    /**
     * Satu rumus terhadap dataset: kuncinya, setiap measure yang dirujuknya, warisan mata uang dan satuannya, dan
     * formatnya. Memulangkan field yang dibaca measure di dalamnya, untuk gerbang data pribadi.
     *
     * @param  array<string, Formula>  $formulas  semua rumus query per kunci
     * @return array<string, string>
     *
     * @throws AnalyticsQueryException
     */
    private function formula(CompiledDataset $dataset, AnalyticsQuery $query, Formula $formula, string $path, array $formulas): array
    {
        if ($dataset->hasField($formula->key) || $dataset->hasMeasure($formula->key)) {
            throw AnalyticsQueryException::invalidQuery("{$path}.key", 'Nama rumus "'.$formula->key.'" sudah dipakai kolom atau nilai data ini. Pilih nama lain.');
        }
        if (! in_array($formula->key, $query->measures, true)) {
            throw AnalyticsQueryException::invalidQuery("{$path}.key", 'Rumus "'.$formula->key.'" belum dipilih sebagai nilai yang dihitung.');
        }

        $uses = [];
        // Kolom mata uang dan satuan berkualifikasi => measure pertama yang memakainya.
        $seen = ['mata uang' => [], 'satuan' => []];
        foreach ($formula->references() as ['key' => $key, 'position' => $position]) {
            if (isset($formulas[$key])) {
                throw AnalyticsQueryException::invalidFormula("{$path}.expression", 'Rumus tidak dapat memakai rumus lain (['.$key.'], di karakter '.$position.'). Tulis ulang isi rumus itu di sini.', $position);
            }
            if (! $dataset->hasMeasure($key)) {
                throw AnalyticsQueryException::formulaMeasureUnknown("{$path}.expression", $key, $position);
            }
            if (! $dataset->isNumericMeasure($key)) {
                throw AnalyticsQueryException::invalidFormula("{$path}.expression", 'Nilai ['.$key.'] bukan angka, jadi tidak dapat dihitung di rumus (karakter '.$position.').', $position);
            }

            $measure = $dataset->measure($key);
            foreach (['mata uang' => $measure->currency, 'satuan' => $measure->unit] as $noun => $column) {
                if ($column === null) {
                    continue;
                }
                $seen[$noun][$dataset->qualified($column)] ??= $key;
                if (count($seen[$noun]) > 1) {
                    throw AnalyticsQueryException::invalidFormula(
                        "{$path}.expression",
                        'Rumus mencampur nilai dengan '.$noun.' berbeda (['.implode('] dan [', array_values($seen[$noun])).'], di karakter '.$position.'). Pakai nilai yang '.$noun.'nya sama.',
                        $position,
                    );
                }
            }

            $uses = [...$uses, ...$this->measureUses($dataset, $key, "{$path}.measures.{$key}")];
        }

        $format = $formula->format();
        if ($format === MeasureFormat::Money && $seen['mata uang'] === []) {
            throw AnalyticsQueryException::invalidQuery("{$path}.format", 'Format uang butuh nilai uang di dalam rumusnya, supaya mata uangnya diketahui.');
        }
        if ($format === MeasureFormat::Quantity && $seen['satuan'] === []) {
            throw AnalyticsQueryException::invalidQuery("{$path}.format", 'Format kuantitas butuh nilai kuantitas di dalam rumusnya, supaya satuannya diketahui.');
        }

        return $uses;
    }

    /**
     * Perbandingan periode butuh rentang waktu yang jelas awal dan akhirnya, dan setiap kolom tanggal yang
     * dikelompokkan harus memakai ukuran waktu: baris periode lalu digabung ke periode yang sedang dilihat menurut
     * embernya, dan tanggal-jam mentah tidak pernah sama di dua periode. Rentang tahun fiskal dihitung sesudah
     * validasi, jadi di sini cukup tokennya dikenal.
     *
     * @throws AnalyticsQueryException
     */
    private function comparable(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal): void
    {
        if ($query->timeRange === null) {
            throw AnalyticsQueryException::invalidQuery('compare', 'Perbandingan periode butuh rentang waktu. Pilih periode lebih dulu, misalnya bulan ini.');
        }
        if (! RelativeRange::isFiscal($query->timeRange->range) && Comparison::closedBounds($query->timeRange, $principal->now()) === null) {
            throw Comparison::notClosed();
        }
        foreach ($query->dimensions as $i => $dimension) {
            if ($dimension->granularity === null && in_array($dimension->field, $dataset->times(), true)) {
                throw AnalyticsQueryException::invalidQuery("dimensions.{$i}", 'Untuk membandingkan periode, kelompokkan kolom tanggal per hari, minggu, bulan, kuartal, atau tahun.');
            }
        }
        foreach ($query->measures as $i => $key) {
            if ($query->formula($key) === null && ! $dataset->isNumericMeasure($key)) {
                throw AnalyticsQueryException::invalidQuery("measures.{$i}", 'Nilai "'.$key.'" bukan angka, jadi selisihnya antarperiode tidak dapat dihitung. Lepas nilai ini untuk membandingkan periode.');
            }
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
