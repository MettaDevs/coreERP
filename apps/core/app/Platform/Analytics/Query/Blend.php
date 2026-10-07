<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Datasets\SharedDimensionRegistry;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;

/**
 * Memvalidasi dan menjalankan dua query biasa secara mandiri, lalu melakukan full outer merge atas hasil agregat.
 * Hak, pemasangan, kebijakan data, cache, batas baris, dan transaksi tetap dijaga `RunQuery` untuk tiap sumber;
 * SQL tidak pernah menggabungkan tabel lintas module.
 */
final class Blend
{
    public function __construct(
        private readonly StoredQuery $queries,
        private readonly RunQuery $run,
        private readonly SharedDimensionRegistry $dimensions,
        private readonly DatasetRegistry $datasets,
    ) {}

    /**
     * Memeriksa bentuk dan setiap query sumber sebelum query pertama dijalankan.
     *
     * @throws AnalyticsQueryException
     */
    public function validate(AnalyticsPrincipal $principal, mixed $input): BlendQuery
    {
        if (! is_array($input) || array_is_list($input) || ! array_key_exists('queries', $input)) {
            throw AnalyticsQueryException::invalidQuery('query', 'Pilih dua data untuk digabungkan.');
        }
        foreach (array_keys($input) as $key) {
            if ($key !== 'queries') {
                throw AnalyticsQueryException::invalidQuery('query.'.$key, 'Bagian ini tidak dikenal dalam gabungan data.');
            }
        }

        $inputs = $input['queries'];
        if (! is_array($inputs) || ! array_is_list($inputs) || count($inputs) !== 2) {
            throw AnalyticsQueryException::invalidQuery('queries', 'Pilih tepat dua data untuk digabungkan.');
        }

        $sources = [];
        $seenDatasets = [];
        $shared = null;
        foreach ($inputs as $index => $queryInput) {
            [$dataset, $query] = $this->queries->validate($principal, $queryInput, "queries.{$index}");

            if (isset($seenDatasets[$dataset->code])) {
                throw AnalyticsQueryException::invalidQuery("queries.{$index}.dataset", 'Pilih dua data yang berbeda untuk digabungkan.');
            }
            $seenDatasets[$dataset->code] = true;

            if (count($query->dimensions) !== 1) {
                throw AnalyticsQueryException::invalidQuery("queries.{$index}.dimensions", 'Setiap data harus dikelompokkan menurut satu kolom bersama.');
            }
            $dimension = $query->dimensions[0];
            $sharedDimension = $dimension->granularity === null ? $dataset->sharedDimension($dimension->field) : null;
            if ($sharedDimension === null) {
                throw AnalyticsQueryException::invalidQuery("queries.{$index}.dimensions.0", 'Pilih kolom bersama yang tersedia pada kedua data.');
            }
            if (! $this->dimensions->supports($sharedDimension)) {
                throw AnalyticsQueryException::invalidQuery("queries.{$index}.dimensions.0", 'Gabungan data belum tersedia untuk kolom ini. Pilih kolom bersama lain.');
            }
            if ($shared !== null && $shared !== $sharedDimension) {
                throw AnalyticsQueryException::invalidQuery("queries.{$index}.dimensions.0", 'Kedua data harus memakai kolom bersama yang sama.');
            }

            $this->assertSingleCurrencyAndUnit($dataset, $query, $index);
            if ($sharedDimension === SharedDimension::Currency) {
                $currencyField = $this->currencyField($dataset, $query);
                if ($currencyField !== null && $currencyField !== $dimension->field) {
                    throw AnalyticsQueryException::invalidQuery("queries.{$index}.measures", 'Saat dikelompokkan menurut mata uang, nilai uang harus memakai kolom mata uang yang sama.');
                }
            }
            $shared = $sharedDimension;
            $sources[] = ['dataset' => $dataset, 'query' => $query];
        }

        return new BlendQuery($shared, $sources);
    }

    /**
     * Bentuk tersimpan: tiap query membawa versi datasetnya sendiri agar penggantian nama kolom bisa dipetakan
     * saat widget lama dibaca. Metadata versi dibuat server, bukan diterima dari layar.
     *
     * @return array{queries: list<array{query: array<string, mixed>, dataset_version: int}>}
     */
    public function forStorage(BlendQuery $blend): array
    {
        return ['queries' => array_map(
            static fn (array $source): array => [
                'query' => StoredQuery::compact($source['query']),
                'dataset_version' => $source['dataset']->version,
            ],
            $blend->sources,
        )];
    }

    /**
     * Bentuk tersimpan dibaca kembali sebagai bentuk query biasa untuk API dan layar. Mendukung bentuk lama
     * tanpa metadata versi agar widget yang dibuat selama pengembangan tetap dapat dibaca.
     *
     * @return array{
     *     query: array{queries: list<array<string, mixed>>},
     *     maps: list<array<string, string>>,
     *     missing: list<array{source: int, path: string, field: string}>,
     *     datasets: list<CompiledDataset|null>
     * }
     *
     * @throws AnalyticsQueryException
     */
    public function readStorage(mixed $stored): array
    {
        if (! is_array($stored) || ! is_array($stored['queries'] ?? null)
            || ! array_is_list($stored['queries']) || count($stored['queries']) !== 2) {
            throw AnalyticsQueryException::invalidQuery('query', 'Bagian ini tidak dapat dibaca. Ubah lalu simpan ulang.');
        }

        $queries = [];
        $maps = [];
        $missing = [];
        $datasets = [];
        foreach ($stored['queries'] as $index => $entry) {
            $query = is_array($entry) && is_array($entry['query'] ?? null) ? $entry['query'] : $entry;
            if (! is_array($query) || ! is_string($query['dataset'] ?? null)) {
                throw AnalyticsQueryException::invalidQuery("query.queries.{$index}", 'Salah satu data pada bagian ini tidak dapat dibaca.');
            }

            $dataset = $this->datasets->find($query['dataset']);
            $datasets[] = $dataset;
            if ($dataset === null) {
                $queries[] = $query;
                $maps[] = [];

                continue;
            }

            $version = is_array($entry) && is_int($entry['dataset_version'] ?? null) ? $entry['dataset_version'] : null;
            $read = StoredQuery::read($dataset, $query, $version);
            $queries[] = $read['query'];
            $maps[] = $read['map'];
            foreach ($read['missing'] as $path => $field) {
                $missing[] = ['source' => $index, 'path' => $path, 'field' => $field];
            }
        }

        return ['query' => ['queries' => $queries], 'maps' => $maps, 'missing' => $missing, 'datasets' => $datasets];
    }

    /**
     * Memetakan kunci kolom tabel gabungan dengan peta kunci masing-masing dataset.
     *
     * @param  array<string, mixed>  $visual
     * @param  list<array<string, string>>  $maps
     * @param  array{queries: list<array<string, mixed>>}  $query
     * @return array<string, mixed>
     */
    public function renameVisual(array $visual, array $maps, array $query): array
    {
        if (! is_array($visual['columns'] ?? null)) {
            return $visual;
        }

        $prefixes = array_map(static fn (array $source): string => (string) ($source['dataset'] ?? '').'.', $query['queries']);
        $dimensionMap = $maps[0] ?? [];
        $visual['columns'] = array_map(static function (mixed $value) use ($maps, $prefixes, $dimensionMap): mixed {
            if (! is_string($value)) {
                return $value;
            }
            foreach ($prefixes as $index => $prefix) {
                if ($prefix !== '.' && str_starts_with($value, $prefix)) {
                    $key = substr($value, strlen($prefix));

                    return $prefix.($maps[$index][$key] ?? $key);
                }
            }

            return $dimensionMap[$value] ?? $value;
        }, $visual['columns']);

        return $visual;
    }

    /**
     * Kolom hasil yang dipakai `WidgetDefinition` untuk memvalidasi tampilan tabel.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(BlendQuery $blend): array
    {
        $first = $blend->sources[0];
        $dimension = $first['query']->dimensions[0];
        $field = $dimension->field;
        $column = ResultColumn::dimension('d0', $dimension, $first['dataset'])->toArray();
        $column['shared_dimension'] = $blend->dimension->value;
        $columns = [$column];

        $currencyKey = $this->currencyOutputKey($blend);
        if ($currencyKey !== null && $currencyKey !== $field) {
            $columns[] = ['key' => $currencyKey, 'kind' => 'dimension', 'caption' => 'Mata uang', 'type' => 'text', 'implicit' => true];
        }
        if ($this->hasUnit($blend)) {
            $columns[] = ['key' => '__unit', 'kind' => 'dimension', 'caption' => 'Satuan', 'type' => 'text', 'implicit' => true];
        }

        foreach ($blend->sources as $source) {
            foreach ($source['query']->measures as $key) {
                $measure = $source['dataset']->measure($key);
                $column = ResultColumn::measure('m0', $key, $source['dataset'])->toArray();
                $column['key'] = $source['dataset']->code.'.'.$key;
                $column['dataset'] = $source['dataset']->code;
                $column['measure'] = $key;
                $column['currency'] = $measure->currency;
                $column['unit'] = $measure->unit;
                $column['currency_key'] = $measure->currency === null ? null : $currencyKey;
                $column['unit_key'] = $measure->unit === null ? null : '__unit';
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Menjalankan tiap sumber melalui jalur query biasa, lalu menggabungkan kelompoknya.
     *
     * @throws AnalyticsQueryException
     */
    public function handle(
        AnalyticsPrincipal $principal,
        mixed $input,
        ?int $cacheTtl = null,
        bool $refresh = false,
        string $source = QueryLog::SOURCE_WIDGET,
    ): BlendResultSet {
        $blend = $this->validate($principal, $input);
        $results = [];
        foreach ($blend->sources as $query) {
            $results[] = $this->run->handle($principal, $query['query'], $cacheTtl, $refresh, $source);
        }

        $sourceMeta = [];
        foreach ($blend->sources as $index => $query) {
            $sourceMeta[] = [
                'dataset' => $query['dataset']->code,
                'dataset_version' => $query['dataset']->version,
                'row_limit' => $results[$index]->meta['row_limit'],
                'truncated' => $results[$index]->meta['truncated'],
                'cached' => $results[$index]->meta['cached'],
                'duration_ms' => $results[$index]->meta['duration_ms'],
                'query_hash' => $results[$index]->meta['query_hash'],
            ];
        }

        return new BlendResultSet(
            columns: $this->columns($blend),
            rows: $this->merge($blend, $results, totals: false),
            totals: $this->merge($blend, $results, totals: true),
            meta: [
                'type' => 'blend',
                'dimension' => $blend->dimension->value,
                'generated_at' => $principal->now()->toIso8601String(),
                'timezone' => $principal->timezone(),
                'truncated' => in_array(true, array_column($sourceMeta, 'truncated'), true),
                'cached' => ! in_array(false, array_column($sourceMeta, 'cached'), true),
                'duration_ms' => array_sum(array_column($sourceMeta, 'duration_ms')),
                'query_hash' => 'sha256:'.hash('sha256', json_encode($blend->normalized(), JSON_THROW_ON_ERROR)),
                'sources' => $sourceMeta,
            ],
        );
    }

    /**
     * @param  list<ResultSet>  $results
     * @return list<array<string, scalar|null>>
     */
    private function merge(BlendQuery $blend, array $results, bool $totals): array
    {
        $firstDimension = $blend->sources[0]['query']->dimensions[0]->field;
        $labelKey = $firstDimension.'__label';
        $currencyKey = $this->currencyOutputKey($blend);
        $unitKey = $this->hasUnit($blend) ? '__unit' : null;
        $measureKeys = [];
        foreach ($blend->sources as $source) {
            foreach ($source['query']->measures as $measure) {
                $measureKeys[] = $source['dataset']->code.'.'.$measure;
            }
        }

        $rows = [];
        $positions = [];
        foreach ($blend->sources as $index => $source) {
            $dataset = $source['dataset'];
            $query = $source['query'];
            $dimension = $query->dimensions[0]->field;
            $currencyField = $this->currencyField($dataset, $query);
            $unitField = $this->unitField($dataset, $query);
            if (! $totals && $blend->dimension === SharedDimension::Currency && $currencyField === $dimension) {
                // Currency is already part of the shared key, so do not split a count-only source from it.
                $currencyField = null;
            }
            $sourceRows = $totals ? $results[$index]->totals : $results[$index]->rows;

            foreach ($sourceRows as $row) {
                $dimensionValue = $totals ? null : ($row[$dimension] ?? null);
                $currencyValue = $currencyField === null ? null : ($row[$currencyField] ?? null);
                $unitValue = $unitField === null ? null : ($row[$unitField] ?? null);
                $key = $this->rowKey($dimensionValue, $currencyField !== null, $currencyValue, $unitField !== null, $unitValue, $totals);

                if (! isset($positions[$key])) {
                    $out = [];
                    if (! $totals) {
                        $out[$firstDimension] = $dimensionValue;
                        if ($this->columnsLabelled($blend)) {
                            $out[$labelKey] = null;
                        }
                    }
                    if ($currencyKey !== null && ($totals || $currencyKey !== $firstDimension)) {
                        $out[$currencyKey] = null;
                    }
                    if ($unitKey !== null) {
                        $out[$unitKey] = null;
                    }
                    foreach ($measureKeys as $measureKey) {
                        $out[$measureKey] = null;
                    }

                    $positions[$key] = count($rows);
                    $rows[] = $out;
                }

                $position = $positions[$key];
                if ($currencyField !== null && $currencyKey !== null && ($totals || $currencyKey !== $firstDimension)) {
                    $rows[$position][$currencyKey] = $currencyValue;
                }
                if ($unitField !== null && $unitKey !== null) {
                    $rows[$position][$unitKey] = $unitValue;
                }
                if (! $totals && $this->columnsLabelled($blend)) {
                    $sourceLabelKey = $dimension.'__label';
                    if (($rows[$position][$labelKey] ?? null) === null && ($row[$sourceLabelKey] ?? null) !== null) {
                        $rows[$position][$labelKey] = $row[$sourceLabelKey];
                    }
                }
                foreach ($query->measures as $measure) {
                    $rows[$position][$dataset->code.'.'.$measure] = $row[$measure] ?? null;
                }
            }
        }

        return array_values($rows);
    }

    private function rowKey(mixed $dimension, bool $hasCurrency, mixed $currency, bool $hasUnit, mixed $unit, bool $totals): string
    {
        $normalize = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        return json_encode([
            'dimension' => $totals ? null : $normalize($dimension),
            'currency' => $hasCurrency ? ['present' => true, 'value' => $normalize($currency)] : ['present' => false],
            'unit' => $hasUnit ? ['present' => true, 'value' => $normalize($unit)] : ['present' => false],
        ], JSON_THROW_ON_ERROR);
    }

    private function currencyOutputKey(BlendQuery $blend): ?string
    {
        $field = null;
        $allUseDimension = true;
        foreach ($blend->sources as $source) {
            $sourceField = $this->currencyField($source['dataset'], $source['query']);
            if ($sourceField === null) {
                continue;
            }
            $field ??= $sourceField;
            if ($sourceField !== $source['query']->dimensions[0]->field || $blend->dimension !== SharedDimension::Currency) {
                $allUseDimension = false;
            }
        }

        return $field === null ? null : ($allUseDimension ? $blend->sources[0]['query']->dimensions[0]->field : '__currency');
    }

    private function hasUnit(BlendQuery $blend): bool
    {
        foreach ($blend->sources as $source) {
            if ($this->unitField($source['dataset'], $source['query']) !== null) {
                return true;
            }
        }

        return false;
    }

    private function columnsLabelled(BlendQuery $blend): bool
    {
        $source = $blend->sources[0];

        return ResultColumn::dimension('d0', $source['query']->dimensions[0], $source['dataset'])->labelKey !== null;
    }

    private function currencyField(CompiledDataset $dataset, AnalyticsQuery $query): ?string
    {
        return $this->measurementDimensionField($dataset, $query, currency: true);
    }

    private function unitField(CompiledDataset $dataset, AnalyticsQuery $query): ?string
    {
        return $this->measurementDimensionField($dataset, $query, currency: false);
    }

    private function measurementDimensionField(CompiledDataset $dataset, AnalyticsQuery $query, bool $currency): ?string
    {
        $fields = [];
        foreach ($query->measures as $key) {
            $measure = $dataset->measure($key);
            $field = $currency ? $measure->currency : $measure->unit;
            if ($field !== null) {
                $fields[$field] = true;
            }
        }

        return array_key_first($fields);
    }

    /**
     * A single result can expose at most one currency and one unit dimension in this first blend version.
     *
     * @throws AnalyticsQueryException
     */
    private function assertSingleCurrencyAndUnit(CompiledDataset $dataset, AnalyticsQuery $query, int $index): void
    {
        foreach ([true, false] as $currency) {
            $fields = [];
            foreach ($query->measures as $key) {
                $measure = $dataset->measure($key);
                $field = $currency ? $measure->currency : $measure->unit;
                if ($field !== null) {
                    $fields[$field] = true;
                }
            }
            if (count($fields) > 1) {
                $kind = $currency ? 'mata uang' : 'satuan';
                throw AnalyticsQueryException::invalidQuery("queries.{$index}.measures", "Satu data gabungan hanya dapat memakai satu kolom {$kind}.");
            }
        }
    }
}
