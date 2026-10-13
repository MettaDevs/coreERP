<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Actions;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Query\TimeGranularity;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Modules\Contracts\FieldType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Mengganti satu tingkat waktu atau hierarki, mempertahankan semua filter yang sudah berlaku. */
final class DrillDown
{
    public function __construct(
        private readonly RunQuery $run,
        private readonly QueryParser $parser,
    ) {}

    /**
     * @param  list<array{field: string, value: scalar|null, granularity?: string|null}>  $path
     * @return array{result: ResultSet, next: array{field: string, granularity: ?string, caption: string}}
     */
    public function run(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, string $field, array $path): array
    {
        $hierarchy = null;
        $levelIndex = null;
        $rootIndex = null;
        foreach ($dataset->hierarchies() as $levels) {
            $root = $levels[0] ?? null;
            $index = array_search($field, $levels, true);
            if ($index === false || $root === null) {
                continue;
            }
            $queryIndex = $this->dimensionIndex($query, $root);
            if ($queryIndex === null) {
                continue;
            }

            $hierarchy = $levels;
            $levelIndex = $index;
            $rootIndex = $queryIndex;
            break;
        }
        if ($rootIndex === null) {
            foreach ($query->dimensions as $index => $dimension) {
                if ($dimension->field === $field) {
                    $rootIndex = $index;
                    break;
                }
            }
        }
        if ($rootIndex === null) {
            throw AnalyticsQueryException::invalidQuery('field', 'Kolom ini tidak berada di hierarki yang dapat ditelusuri.');
        }

        $root = $query->dimensions[$rootIndex];
        $next = $hierarchy === null
            ? $this->nextTime($dataset, $root, $field, $path)
            : $this->nextField($dataset, $hierarchy, $levelIndex ?? 0, $field, $path);
        $this->assertPath($dataset, $query, $hierarchy, $path);

        $normalized = $query->normalized();
        $normalized['sort'] = [];
        $normalized['dimensions'][$rootIndex] = $next['granularity'] === null
            ? ['field' => $next['field']]
            : ['field' => $next['field'], 'granularity' => $next['granularity']];
        $filters = $query->filters;
        $pathFilters = [];
        foreach ($path as $step) {
            if ($step['value'] === null) {
                throw AnalyticsQueryException::invalidQuery('values', 'Kelompok kosong tidak dapat ditelusuri lebih jauh.');
            }
            $fieldKey = $step['field'];
            // Time-path entries repeat one field; the last bucket is the narrowest one.
            $pathFilters[$fieldKey] = $this->filterValue($dataset, $fieldKey, $step['value'], $step['granularity'] ?? null, $principal);
        }
        foreach ($pathFilters as $fieldKey => $value) {
            $filters[$fieldKey] = $this->narrow($dataset, $fieldKey, $filters[$fieldKey] ?? null, $value);
        }
        $normalized['filters'] = $filters;
        $drillQuery = $this->parser->parse($normalized);
        $result = $this->run->handle($principal, $drillQuery, cacheTtl: 0, source: QueryLog::SOURCE_WIDGET);

        return ['result' => $result, 'next' => $next];
    }

    private function dimensionIndex(AnalyticsQuery $query, string $field): ?int
    {
        foreach ($query->dimensions as $index => $dimension) {
            if ($dimension->field === $field) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array{field: string, value: scalar|null, granularity?: string|null}> $path
     * @return array{field: string, granularity: ?string, caption: string}
     */
    private function nextTime(CompiledDataset $dataset, Dimension $root, string $field, array $path): array
    {
        if (! in_array($field, $dataset->times(), true)) {
            throw AnalyticsQueryException::invalidQuery('field', 'Kolom ini bukan kolom waktu atau hierarki.');
        }
        if ($root->granularity === null && $dataset->filterField($field)->type !== FieldType::Date) {
            throw AnalyticsQueryException::invalidQuery('field', 'Pilih ukuran waktu sebelum menelusuri kolom ini.');
        }
        $current = $root->granularity?->value;
        foreach ($path as $step) {
            if ($step['field'] === $field && ($step['granularity'] ?? null) !== null) {
                $current = $step['granularity'];
            }
        }
        $next = match ($current) {
            null, 'year' => TimeGranularity::Year,
            'quarter' => TimeGranularity::Month,
            'month' => TimeGranularity::Day,
            default => null,
        };
        if ($next === null || $next->value === $current) {
            throw AnalyticsQueryException::invalidQuery('field', 'Periode ini sudah berada di tingkat paling rinci.');
        }

        return ['field' => $field, 'granularity' => $next->value, 'caption' => $dataset->filterField($field)->caption];
    }

    /**
     * @param  list<string>  $hierarchy
     * @param  list<array{field: string, value: scalar|null, granularity?: string|null}>  $path
     * @return array{field: string, granularity: ?string, caption: string}
     */
    private function nextField(CompiledDataset $dataset, array $hierarchy, int $levelIndex, string $field, array $path): array
    {
        $pathFields = array_values(array_unique(array_column($path, 'field')));
        $expected = array_slice($hierarchy, 0, $levelIndex + 1);
        $hierarchyPath = array_values(array_filter($pathFields, static fn (string $field): bool => in_array($field, $hierarchy, true)));
        if ($hierarchyPath !== $expected) {
            throw AnalyticsQueryException::invalidQuery('values', 'Sebelum menelusuri tingkat ini, pilih nilai pada tingkat sebelumnya.');
        }
        $nextField = $hierarchy[$levelIndex + 1] ?? null;
        if ($nextField === null || ! $dataset->hasField($nextField)) {
            throw AnalyticsQueryException::invalidQuery('field', 'Hierarki ini sudah berada di tingkat paling rinci.');
        }

        return ['field' => $nextField, 'granularity' => null, 'caption' => $dataset->filterField($nextField)->caption];
    }

    /** @param list<string>|null $hierarchy
     * @param  list<array{field: string, value: scalar|null, granularity?: string|null}>  $path
     */
    private function assertPath(CompiledDataset $dataset, AnalyticsQuery $query, ?array $hierarchy, array $path): void
    {
        $allowed = array_fill_keys(array_map(static fn (Dimension $dimension): string => $dimension->field, $query->dimensions), true);
        if ($hierarchy !== null) {
            foreach ($hierarchy as $field) {
                $allowed[$field] = true;
            }
        }
        foreach ($path as $index => $step) {
            if (! isset($allowed[$step['field']]) || ! $dataset->hasField($step['field'])) {
                throw AnalyticsQueryException::fieldUnknown("values.{$index}.field", $step['field']);
            }
            $granularity = $step['granularity'] ?? null;
            if ($granularity !== null && (! in_array($step['field'], $dataset->times(), true) || TimeGranularity::tryFrom($granularity) === null)) {
                throw AnalyticsQueryException::invalidQuery("values.{$index}.granularity", 'Ukuran waktu pada pilihan ini tidak tersedia.');
            }
        }
    }

    /** @return string|list<string> */
    private function filterValue(CompiledDataset $dataset, string $key, string|int|float|bool $value, ?string $granularity, AnalyticsPrincipal $principal): string|array
    {
        if ($granularity !== null) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value, $principal->timezone());
            if ($start === null) {
                throw AnalyticsQueryException::invalidQuery('values', 'Periode ini tidak dapat dibaca. Muat ulang dasbor.');
            }
            [$from, $to] = match ($granularity) {
                'day' => [$start, $start],
                'week' => [$start->startOfWeek(CarbonInterface::MONDAY), $start->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()],
                'month' => [$start->startOfMonth(), $start->endOfMonth()->startOfDay()],
                'quarter' => [$start->startOfQuarter(), $start->endOfQuarter()->startOfDay()],
                default => [$start->startOfYear(), $start->endOfYear()->startOfDay()],
            };

            return $from->toDateString().'..'.$to->toDateString();
        }

        $type = $dataset->filterField($key)->type;
        if (in_array($type, [FieldType::Boolean, FieldType::Option, FieldType::Reference], true)) {
            return [(string) $value];
        }
        if ($type === FieldType::Text) {
            return "'".str_replace("'", "''", (string) $value)."'";
        }

        return (string) $value;
    }

    /** @param string|list<string>|null $existing
     * @param  string|list<string>  $next
     * @return string|list<string>
     */
    private function narrow(CompiledDataset $dataset, string $field, string|array|null $existing, string|array $next): string|array
    {
        if ($existing === null || $existing === $next) {
            return $next;
        }
        if (is_array($existing) && is_array($next)) {
            $intersection = array_values(array_intersect($existing, $next));
            if ($intersection !== []) {
                return $intersection;
            }
        }
        if (is_string($existing) && is_string($next)
            && in_array($dataset->filterField($field)->type, [FieldType::Text, FieldType::Number, FieldType::Date, FieldType::DateTime], true)
            && ! str_contains($existing, '|')) {
            return $existing.'&'.$next;
        }

        throw AnalyticsQueryException::invalidQuery('values', 'Pilihan ini bertentangan dengan saringan bagian. Hapus saringan tersebut terlebih dahulu.');
    }
}
