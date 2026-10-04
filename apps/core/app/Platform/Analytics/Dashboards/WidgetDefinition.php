<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Dashboards;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Security\AnalyticsPrincipal;

/**
 * Isi widget diperiksa saat disimpan (area 6.4): query lewat {@see StoredQuery::validate()} terhadap dataset saat
 * ini dan hak penyimpannya, lalu `visual` lewat aturan per jenis widget di `docs/todo/analitik/dasbor-dan-visual.md`
 * bagian *Widget*. Widget yang tersimpan karena itu selalu dapat digambar; yang kemudian rusak hanya karena
 * dataset berubah, dan itu dilaporkan sebagai `field_removed` saat dibaca.
 *
 * | Jenis | `visual` | Kebutuhan query |
 * | --- | --- | --- |
 * | `kpi` | `{measure, thresholds?, compact?}` | Satu measure, tanpa pengelompokan (garis kecil fase 2) |
 * | `bar`, `column` | `{x, series?, y: [measure], stacked?, show_values?}` | 1–2 pengelompok, semuanya dipakai `x`/`series`; 1–4 measure |
 * | `line`, `area` | Sama, `x` wajib kolom tanggal | Sama |
 * | `donut` | `{category, value, max_slices?}` | Satu pengelompok, satu measure |
 * | `table` | `{columns: [kunci], show_totals?}` | Apa pun dalam batas query |
 * | `text` | `{text}` | Tanpa query; teks biasa, bukan Markdown atau HTML |
 *
 * Bagian `visual` yang tidak dikenal ditolak, bukan diabaikan, seperti kunci query. Galatnya
 * `analytics.invalid_visual` berpath `visual.<bagian>`, atau `query.<bagian>` bila query tidak cocok dengan
 * jenis widgetnya.
 */
final class WidgetDefinition
{
    /** Gaya rentang ambang tile, mengikuti Cue Setup Business Central; layar memetakannya ke token tema. */
    public const THRESHOLD_STYLES = ['favorable', 'unfavorable', 'ambiguous', 'subordinate', 'none'];

    public const STACKING = ['none', 'stacked', 'percent'];

    private const MAX_TEXT_LENGTH = 2000;

    private const MAX_MEASURES_PER_CHART = 4;

    public function __construct(private readonly StoredQuery $queries) {}

    /**
     * @return array{dataset_code: ?string, dataset_version: ?int, query: ?array<string, mixed>, visual: array<string, mixed>}
     *
     * @throws AnalyticsQueryException
     */
    public function validate(AnalyticsPrincipal $principal, string $type, mixed $query, mixed $visual): array
    {
        $visual = $this->object($visual ?? [], 'visual', 'Tampilan widget harus berupa pasangan bagian dan isinya.');

        if ($type === 'text') {
            if ($query !== null) {
                throw self::invalid('query', 'Widget teks tidak memakai query.');
            }

            return ['dataset_code' => null, 'dataset_version' => null, 'query' => null, 'visual' => $this->text($visual)];
        }

        [$dataset, $parsed] = $this->queries->validate($principal, $query);

        $visual = match ($type) {
            'kpi' => $this->kpi($parsed, $visual),
            'bar', 'column' => $this->cartesian($dataset, $parsed, $visual, timeAxis: false),
            'line', 'area' => $this->cartesian($dataset, $parsed, $visual, timeAxis: true),
            'donut' => $this->donut($parsed, $visual),
            'table' => $this->table($parsed, $visual),
            default => throw self::invalid('type', 'Jenis widget tidak dikenal.'),
        };

        return ['dataset_code' => $dataset->code, 'dataset_version' => $dataset->version, 'query' => StoredQuery::compact($parsed), 'visual' => $visual];
    }

    /**
     * `visual` widget lama dengan kunci yang diganti nama module, memakai peta yang sama dengan query-nya
     * ({@see StoredQuery::read()}).
     *
     * @param  array<string, mixed>  $visual
     * @param  array<string, string>  $map  kunci lama => kunci baru
     * @return array<string, mixed>
     */
    public static function renameVisual(array $visual, array $map): array
    {
        if ($map === []) {
            return $visual;
        }
        $key = static fn (mixed $value): mixed => is_string($value) ? ($map[$value] ?? $value) : $value;

        foreach (['measure', 'x', 'series', 'category', 'value'] as $part) {
            if (array_key_exists($part, $visual)) {
                $visual[$part] = $key($visual[$part]);
            }
        }
        foreach (['y', 'columns'] as $part) {
            if (is_array($visual[$part] ?? null)) {
                $visual[$part] = array_map($key, $visual[$part]);
            }
        }

        return $visual;
    }

    /**
     * @param  array<string, mixed>  $visual
     * @return array<string, mixed>
     */
    private function text(array $visual): array
    {
        $this->knownKeys($visual, ['text']);
        $text = $visual['text'] ?? null;
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw self::invalid('visual.text', 'Isi teks widget, paling panjang '.self::MAX_TEXT_LENGTH.' karakter.');
        }

        return ['text' => $text];
    }

    /**
     * @param  array<string, mixed>  $visual
     * @return array<string, mixed>
     */
    private function kpi(AnalyticsQuery $query, array $visual): array
    {
        $this->knownKeys($visual, ['measure', 'thresholds', 'compact']);
        if (count($query->measures) !== 1) {
            throw self::invalid('query.measures', 'Tile angka menghitung tepat satu nilai.');
        }
        if ($query->dimensions !== []) {
            throw self::invalid('query.dimensions', 'Tile angka tidak memakai pengelompokan.');
        }

        $measure = $visual['measure'] ?? $query->measures[0];
        if ($measure !== $query->measures[0]) {
            throw self::invalid('visual.measure', 'Nilai tile harus nilai yang dihitung query-nya.');
        }

        $out = ['measure' => $measure];
        if (isset($visual['thresholds'])) {
            $out['thresholds'] = $this->thresholds($visual['thresholds']);
        }
        if (isset($visual['compact'])) {
            $out['compact'] = $this->flag($visual['compact'], 'visual.compact');
        }

        return $out;
    }

    /** @return array{threshold1: int|float, threshold2: int|float, low: string, middle: string, high: string} */
    private function thresholds(mixed $value): array
    {
        $thresholds = $this->object($value, 'visual.thresholds', 'Ambang tile harus berisi dua batas dan gaya tiap rentang.');
        $this->knownKeys($thresholds, ['threshold1', 'threshold2', 'low', 'middle', 'high'], 'visual.thresholds');

        foreach (['threshold1', 'threshold2'] as $key) {
            if (! is_int($thresholds[$key] ?? null) && ! is_float($thresholds[$key] ?? null)) {
                throw self::invalid("visual.thresholds.{$key}", 'Isi batas ambang dengan angka.');
            }
        }
        /** @var int|float $low */
        $low = $thresholds['threshold1'];
        /** @var int|float $high */
        $high = $thresholds['threshold2'];
        if ($low > $high) {
            throw self::invalid('visual.thresholds.threshold2', 'Batas kedua tidak boleh lebih kecil dari batas pertama.');
        }

        $out = ['threshold1' => $low, 'threshold2' => $high];
        foreach (['low', 'middle', 'high'] as $range) {
            $style = $thresholds[$range] ?? 'none';
            if (! in_array($style, self::THRESHOLD_STYLES, true)) {
                throw self::invalid("visual.thresholds.{$range}", 'Gaya rentang harus salah satu dari: '.implode(', ', self::THRESHOLD_STYLES).'.');
            }
            $out[$range] = $style;
        }

        return $out;
    }

    /**
     * Grafik batang, kolom, garis, dan area: sumbu `x` dan `series` memakai semua pengelompok query, `y` memakai
     * measure query.
     *
     * @param  array<string, mixed>  $visual
     * @return array<string, mixed>
     */
    private function cartesian(CompiledDataset $dataset, AnalyticsQuery $query, array $visual, bool $timeAxis): array
    {
        $this->knownKeys($visual, ['x', 'series', 'y', 'stacked', 'show_values']);
        $dimensions = array_map(static fn (Dimension $dimension): string => $dimension->field, $query->dimensions);
        if ($dimensions === [] || count($dimensions) > 2) {
            throw self::invalid('query.dimensions', 'Grafik butuh satu atau dua kolom pengelompokan.');
        }
        if (count($query->measures) > self::MAX_MEASURES_PER_CHART) {
            throw self::invalid('query.measures', 'Grafik menggambar paling banyak '.self::MAX_MEASURES_PER_CHART.' nilai.');
        }

        $x = $visual['x'] ?? null;
        if (! is_string($x) || ! in_array($x, $dimensions, true)) {
            throw self::invalid('visual.x', 'Pilih sumbu mendatar dari kolom pengelompokan.');
        }
        if ($timeAxis && ! in_array($x, $dataset->times(), true)) {
            throw self::invalid('visual.x', 'Grafik garis dan area butuh pengelompokan menurut tanggal pada sumbu mendatar.');
        }

        $out = ['x' => $x];
        $series = $visual['series'] ?? null;
        if ($series !== null) {
            if (! is_string($series) || $series === $x || ! in_array($series, $dimensions, true)) {
                throw self::invalid('visual.series', 'Seri harus kolom pengelompokan yang lain dari sumbu mendatar.');
            }
            $out['series'] = $series;
        }
        if (count($dimensions) !== count($out)) {
            throw self::invalid('visual.series', 'Kolom pengelompokan kedua harus dipakai sebagai seri.');
        }

        $out['y'] = $this->subset($visual['y'] ?? null, $query->measures, 'visual.y', 'Pilih nilai yang digambar dari nilai yang dihitung query.');

        if (isset($visual['stacked'])) {
            if (! in_array($visual['stacked'], self::STACKING, true)) {
                throw self::invalid('visual.stacked', 'Susunan harus salah satu dari: '.implode(', ', self::STACKING).'.');
            }
            $out['stacked'] = $visual['stacked'];
        }
        if (isset($visual['show_values'])) {
            $out['show_values'] = $this->flag($visual['show_values'], 'visual.show_values');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $visual
     * @return array<string, mixed>
     */
    private function donut(AnalyticsQuery $query, array $visual): array
    {
        $this->knownKeys($visual, ['category', 'value', 'max_slices']);
        if (count($query->dimensions) !== 1) {
            throw self::invalid('query.dimensions', 'Grafik donat butuh tepat satu kolom pengelompokan.');
        }
        if (count($query->measures) !== 1) {
            throw self::invalid('query.measures', 'Grafik donat menggambar tepat satu nilai.');
        }

        $category = $visual['category'] ?? $query->dimensions[0]->field;
        if ($category !== $query->dimensions[0]->field) {
            throw self::invalid('visual.category', 'Bagian donat harus kolom pengelompokan query-nya.');
        }
        $value = $visual['value'] ?? $query->measures[0];
        if ($value !== $query->measures[0]) {
            throw self::invalid('visual.value', 'Besar bagian donat harus nilai yang dihitung query-nya.');
        }

        $out = ['category' => $category, 'value' => $value];
        if (isset($visual['max_slices'])) {
            if (! is_int($visual['max_slices']) || $visual['max_slices'] < 2 || $visual['max_slices'] > 20) {
                throw self::invalid('visual.max_slices', 'Jumlah bagian donat antara 2 dan 20.');
            }
            $out['max_slices'] = $visual['max_slices'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $visual
     * @return array<string, mixed>
     */
    private function table(AnalyticsQuery $query, array $visual): array
    {
        $this->knownKeys($visual, ['columns', 'show_totals']);
        $keys = [...array_map(static fn (Dimension $dimension): string => $dimension->field, $query->dimensions), ...$query->measures];

        $out = ['columns' => $this->subset($visual['columns'] ?? null, $keys, 'visual.columns', 'Pilih kolom tabel dari kolom pengelompokan dan nilai yang dihitung query.')];
        if (isset($visual['show_totals'])) {
            $out['show_totals'] = $this->flag($visual['show_totals'], 'visual.show_totals');
        }

        return $out;
    }

    /**
     * Daftar kunci tanpa ganda yang semuanya anggota `$allowed`, tidak kosong.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function subset(mixed $value, array $allowed, string $path, string $message): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw self::invalid($path, $message);
        }
        foreach ($value as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw self::invalid($path, $message);
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw self::invalid($path, 'Setiap kolom hanya boleh dipilih sekali.');
        }

        return $value;
    }

    private function flag(mixed $value, string $path): bool
    {
        return is_bool($value) ? $value : throw self::invalid($path, 'Isian ini harus berupa true atau false.');
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path, string $message): array
    {
        // `{}` dari JSON terbaca sebagai daftar kosong, jadi objek kosong juga objek.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($path, $message);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $visual
     * @param  list<string>  $known
     */
    private function knownKeys(array $visual, array $known, string $path = 'visual'): void
    {
        foreach (array_keys($visual) as $key) {
            if (! in_array($key, $known, true)) {
                throw self::invalid($path.'.'.$key, 'Bagian "'.$key.'" tidak dikenal untuk jenis widget ini.');
            }
        }
    }

    private static function invalid(string $field, string $message): AnalyticsQueryException
    {
        return new AnalyticsQueryException('analytics.invalid_visual', $message, 422, $field);
    }
}
