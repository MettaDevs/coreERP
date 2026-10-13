<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Actions;

use App\Platform\Analytics\Dashboards\SlicerDefinitions;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\ResultColumn;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Reporting\Support\Rendering\RenderedFile;
use App\Platform\Reporting\Support\Rendering\RenderException;
use App\Platform\Reporting\Support\Rendering\TypedSheetWriter;
use App\Platform\Reporting\Support\ValueFormat;
use App\Platform\Tenant\Models\TenantMembership;
use stdClass;
use Throwable;

/** Merender ekspor bagian dan daftar drill dengan principal dan data yang terbaru saat job berjalan. */
final class AnalyticsExporter
{
    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryParser $parser,
        private readonly RunQuery $run,
        private readonly DrillThrough $drill,
        private readonly SlicerDefinitions $slicers,
        private readonly UserClock $clock,
    ) {}

    /**
     * @param  callable(int, int): void  $progress
     * @return array{file: RenderedFile, rows: int, name: string}
     */
    public function render(stdClass $export, TenantMembership $membership, callable $progress): array
    {
        $parameters = json_decode((string) $export->parameters, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($parameters) || ! is_array($parameters['query'] ?? null) || ! is_array($parameters['locked_filters'] ?? null)) {
            throw new RenderException('Permintaan ekspor analitik tidak dapat dibaca. Minta ekspor lagi.');
        }

        $dataset = $this->datasets->find((string) $export->report_code)
            ?? throw new RenderException('Data yang dipakai bagian ini tidak tersedia lagi.');
        $timezone = $this->clock->timezoneFor($membership->user, $export->legal_entity_id);
        $locked = $parameters['locked_filters'];
        $principal = UserPrincipal::fromMembership($membership, $timezone, [$dataset->code => $locked]);

        try {
            $this->access->authorize($principal, $dataset);
            $this->slicers->validateLockedFilters($dataset, $locked, $principal);
            $query = $this->parser->parse($parameters['query']);
        } catch (AnalyticsQueryException $exception) {
            throw new RenderException($exception->getMessage(), previous: $exception);
        }

        if (($parameters['export_type'] ?? null) === 'widget') {
            return $this->widget($export, $principal, $query, $parameters, $progress);
        }
        if (($parameters['export_type'] ?? null) !== 'drill') {
            throw new RenderException('Jenis ekspor analitik ini tidak dikenal.');
        }

        return $this->drillRows($export, $principal, $dataset, $query, $parameters, $progress);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  callable(int, int): void  $progress
     * @return array{file: RenderedFile, rows: int, name: string}
     */
    private function widget(stdClass $export, UserPrincipal $principal, AnalyticsQuery $query, array $parameters, callable $progress): array
    {
        $result = $this->run->handle(
            $principal,
            $query,
            cacheTtl: is_int($parameters['cache_ttl_seconds'] ?? null) ? $parameters['cache_ttl_seconds'] : null,
            source: QueryLog::SOURCE_WIDGET,
        );
        $columns = $this->widgetColumns($result, $parameters);
        $formats = array_map(static function (array $column) use ($principal): ?ValueFormat {
            if ($column['kind'] === ResultColumn::MEASURE) {
                return $column['format'] === 'percent'
                    ? new ValueFormat(ValueFormat::PERCENT)
                    : new ValueFormat(ValueFormat::NUMBER);
            }

            return match ($column['type']) {
                'date', 'period' => new ValueFormat(ValueFormat::DATE),
                'datetime' => new ValueFormat(ValueFormat::DATETIME, timezone: $principal->timezone()),
                default => null,
            };
        }, $columns);
        $rows = $result->rows;
        $path = $this->temporaryPath();
        $file = new RenderedFile($path, 'xlsx');

        try {
            $writer = new TypedSheetWriter('xlsx', $path);
            $writer->sheet($export->report_name);
            $writer->header(array_column($columns, 'caption'));
            foreach ($rows as $index => $row) {
                $writer->row(array_map(static fn (array $column): string|int|float|null => self::scalar($row[$column['key']] ?? null), $columns), $formats);
                if (($index + 1) % 1000 === 0) {
                    $progress($index + 1, count($rows));
                }
            }
            $writer->close();
        } catch (Throwable $exception) {
            $file->cleanup();
            throw $exception;
        }

        return ['file' => $file, 'rows' => count($rows), 'name' => (string) $export->report_name];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  callable(int, int): void  $progress
     * @return array{file: RenderedFile, rows: int, name: string}
     */
    private function drillRows(stdClass $export, UserPrincipal $principal, CompiledDataset $dataset, AnalyticsQuery $query, array $parameters, callable $progress): array
    {
        $values = DrillValues::parse($parameters['values'] ?? []);
        $visual = is_array($parameters['visual'] ?? null) ? $parameters['visual'] : [];
        $type = is_string($parameters['type'] ?? null) ? $parameters['type'] : 'table';
        $maximum = max(1, (int) config('reporting.max_rows', 50000));
        $path = $this->temporaryPath();
        $file = new RenderedFile($path, 'xlsx');
        $writer = null;
        $written = 0;
        $cursor = null;
        $headers = null;
        $formats = [];

        try {
            $writer = new TypedSheetWriter('xlsx', $path);
            $writer->sheet($export->report_name);
            do {
                $page = $this->drill->page($dataset, $query, $principal, $values, $visual, $type, $cursor);
                if ($headers === null) {
                    $headers = array_column($page['columns'], 'caption');
                    $writer->header($headers);
                    $formats = $this->drillFormats($page['columns'], $principal->timezone());
                }
                foreach ($page['rows'] as $row) {
                    if ($written >= $maximum) {
                        throw new RenderException("Daftar terlalu besar untuk satu ekspor (batas {$maximum} baris). Persempit saringannya.");
                    }
                    $writer->row(array_map(static fn (array $column): string|int|float|null => self::scalar($row[$column['key']] ?? null), $page['columns']), $formats);
                    $written++;
                    if ($written % 1000 === 0) {
                        $progress($written, $maximum);
                    }
                }
                $cursor = $page['next_cursor'];
            } while ($cursor !== null);
            $writer->close();
        } catch (Throwable $exception) {
            $file->cleanup();
            throw $exception;
        }

        return ['file' => $file, 'rows' => $written, 'name' => (string) $export->report_name];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<array{key: string, caption: string, kind: string, type: string, format: ?string}>
     */
    private function widgetColumns(ResultSet $result, array $parameters): array
    {
        $type = $parameters['type'] ?? null;
        $visual = is_array($parameters['visual'] ?? null) ? $parameters['visual'] : [];
        $chosen = [];
        if ($type === 'table' && is_array($visual['columns'] ?? null)) {
            $chosen = array_fill_keys(array_filter($visual['columns'], 'is_string'), true);
        } elseif (in_array($type, ['bar', 'column', 'line', 'area'], true)) {
            foreach (['x', 'series'] as $key) {
                if (is_string($visual[$key] ?? null)) {
                    $chosen[$visual[$key]] = true;
                }
            }
            foreach (is_array($visual['y'] ?? null) ? $visual['y'] : [] as $key) {
                if (is_string($key)) {
                    $chosen[$key] = true;
                }
            }
        } elseif ($type === 'donut') {
            foreach (['category', 'value'] as $key) {
                if (is_string($visual[$key] ?? null)) {
                    $chosen[$visual[$key]] = true;
                }
            }
        } else {
            foreach ($result->columns as $column) {
                if (! $column->implicit) {
                    $chosen[$column->key] = true;
                }
            }
        }
        foreach ($result->columns as $column) {
            if ($column->implicit) {
                $chosen[$column->key] = true;
            }
        }

        return array_values(array_map(static fn ($column): array => [
            'key' => $column->key,
            'caption' => $column->caption,
            'kind' => $column->kind,
            'type' => $column->type,
            'format' => $column->format,
        ], array_filter($result->columns, static function ($column) use ($chosen): bool {
            return isset($chosen[$column->key]);
        })));
    }

    /** @param list<array{key: string, caption: string, type: string}> $columns
     * @return list<?ValueFormat>
     */
    private function drillFormats(array $columns, string $timezone): array
    {
        return array_map(static function (array $column) use ($timezone): ?ValueFormat {
            return match ($column['type']) {
                'date', 'period' => new ValueFormat(ValueFormat::DATE),
                'datetime' => new ValueFormat(ValueFormat::DATETIME, timezone: $timezone),
                'number' => new ValueFormat(ValueFormat::NUMBER),
                default => null,
            };
        }, $columns);
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'coreerp-analytics-');
        if ($path === false) {
            throw new RenderException('Berkas sementara untuk ekspor tidak dapat dibuat.');
        }

        return $path;
    }

    private static function scalar(mixed $value): string|int|float|null
    {
        return match (true) {
            $value === null, is_string($value), is_int($value), is_float($value) => $value,
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            default => (string) $value,
        };
    }
}
