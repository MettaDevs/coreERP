<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Actions;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetCatalog;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\CompiledQuery;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Query\JoinPlanner;
use App\Platform\Analytics\Query\LabelResolver;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\RelativeRange;
use App\Platform\Analytics\Query\ResultColumn;
use App\Platform\Analytics\Query\TimeGranularity;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Modules\Contracts\FieldFilterExpression;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\InvalidFilterExpression;
use App\Platform\Modules\Contracts\TenantRunner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Baris di balik satu kelompok, selalu dengan scope tenant, kebijakan data, dan hak pembacanya. */
final class DrillThrough
{
    public const PAGE_SIZE = 100;

    public function __construct(
        private readonly DatasetAccess $access,
        private readonly PersonalDataGate $personalData,
        private readonly DatasetCatalog $catalog,
        private readonly QueryValidator $validator,
        private readonly QueryNormalizer $normalizer,
        private readonly JoinPlanner $joins,
        private readonly DataPolicyScope $scope,
        private readonly QueryExecutor $executor,
        private readonly LabelResolver $labels,
        private readonly TenantRunner $tenants,
    ) {}

    /**
     * @param  list<array{field: string, value: scalar|null, granularity?: string|null}>  $values
     * @param  array<string, mixed>  $visual
     * @return array{columns: list<array{key: string, caption: string, type: string, label_key?: string}>, rows: list<array<string, scalar|null>>, next_cursor: ?string, record_route: ?string, generated_at: string}
     */
    public function page(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, array $values, array $visual, string $type, ?string $cursor): array
    {
        $query = $this->normalizer->normalize($query);
        $this->access->authorize($principal, $dataset);
        $this->validator->validate($dataset, $query, $principal);
        $dimensions = [];
        foreach ($query->dimensions as $dimension) {
            $dimensions[$dimension->field] = $dimension;
        }
        $allowedFields = array_fill_keys(array_keys($dimensions), true);
        foreach ($dataset->hierarchies() as $levels) {
            if (isset($dimensions[$levels[0] ?? ''])) {
                foreach ($levels as $level) {
                    $allowedFields[$level] = true;
                }
            }
        }

        $clicked = [];
        foreach ($values as $index => $item) {
            $dimension = $dimensions[$item['field']] ?? null;
            $granularity = $item['granularity'] ?? null;
            if (! isset($allowedFields[$item['field']]) || ! $dataset->hasField($item['field'])
                || ($granularity !== null && (! in_array($item['field'], $dataset->times(), true) || TimeGranularity::tryFrom($granularity) === null))
                || ($dimension !== null && $dimension->granularity?->value !== $granularity && $granularity === null)) {
                throw AnalyticsQueryException::invalidQuery("values.{$index}", 'Nilai ini bukan bagian dari kelompok yang dipilih. Muat ulang bagian tersebut.');
            }
            if ($dimension === null && $granularity !== null) {
                throw AnalyticsQueryException::invalidQuery("values.{$index}.granularity", 'Field hierarki bukan field waktu.');
            }
            $this->personalData->assertUsable($dataset, $principal, ["values.{$index}.field" => $item['field']]);
            $clicked[] = [new Dimension($item['field'], $granularity === null ? $dimension?->granularity : TimeGranularity::from($granularity)), $item['value']];
        }

        $model = new $dataset->model;
        $key = $model->getKeyName();
        if ($dataset->isQuerySource() && ! $dataset->hasField($key)) {
            throw AnalyticsQueryException::invalidQuery('widget', 'Data ini belum mendukung pembukaan baris satu per satu.');
        }

        $description = $this->catalog->describe($dataset, $principal);
        $visible = array_fill_keys(array_column($description['fields'], 'key'), true);
        $requested = $type === 'table' && is_array($visual['columns'] ?? null)
            ? $visual['columns']
            : array_map(static fn (Dimension $dimension): string => $dimension->field, $query->dimensions);
        $fieldKeys = array_values(array_unique(array_filter(
            $requested,
            static fn (mixed $field): bool => is_string($field) && isset($visible[$field]),
        )));
        if ($fieldKeys === []) {
            $fieldKeys = array_column($description['fields'], 'key');
        }

        return $this->tenants->runFor($principal->tenantId(), function () use ($dataset, $query, $principal, $clicked, $fieldKeys, $key, $cursor, $description): array {
            return $this->read($dataset, $query, $principal, $clicked, $fieldKeys, $key, $cursor, $description);
        });
    }

    /**
     * @param  list<array{0: Dimension, 1: scalar|null}>  $clicked
     * @param  list<string>  $fieldKeys
     * @param  array<string, mixed>  $description
     * @return array{columns: list<array{key: string, caption: string, type: string, label_key?: string}>, rows: list<array<string, scalar|null>>, next_cursor: ?string, record_route: ?string, generated_at: string}
     */
    private function read(CompiledDataset $dataset, AnalyticsQuery $query, AnalyticsPrincipal $principal, array $clicked, array $fieldKeys, string $key, ?string $cursor, array $description): array
    {
        $builder = $dataset->baseQuery();
        $builder->select([]);
        $columns = [];
        foreach ($fieldKeys as $field) {
            $columns[] = $dataset->qualified($field);
        }
        foreach (array_keys($query->filters) as $field) {
            $columns[] = $dataset->qualified($field);
        }
        if ($query->timeRange !== null) {
            $timeField = $query->timeRange->field ?? $dataset->defaultTime();
            if ($timeField !== null) {
                $columns[] = $dataset->qualified($timeField);
            }
        }
        foreach ($clicked as [$dimension]) {
            $columns[] = $dataset->qualified($dimension->field);
        }
        if ($dataset->policy !== null) {
            $columns[] = $dataset->qualified($dataset->policy['legal_entity']);
            if ($dataset->policy['operating_unit'] !== null) {
                $columns[] = $dataset->qualified($dataset->policy['operating_unit']);
            }
        }
        $references = array_values(array_filter($fieldKeys, fn (string $field): bool => $dataset->reference($field) !== null));
        $this->joins->apply($builder, $dataset, $columns, $references);
        $this->scope->apply($builder, $dataset, $principal);

        foreach ($query->filters as $field => $value) {
            $this->applyFilter($builder, $dataset, $field, $value, $principal);
        }
        if ($query->timeRange !== null) {
            $field = $query->timeRange->field ?? $dataset->defaultTime();
            if ($field !== null) {
                $this->applyFilter($builder, $dataset, $field, RelativeRange::expression($query->timeRange->range, $principal->now()), $principal);
            }
        }
        foreach ($clicked as [$dimension, $value]) {
            if ($value === null) {
                $builder->whereNull($dataset->qualified($dimension->field));

                continue;
            }
            $filter = $this->clickedFilter($dataset, $dimension, $value, $principal->timezone());
            $this->applyFilter($builder, $dataset, $dimension->field, $filter, $principal);
        }

        $idColumn = $dataset->qualified($key);
        $builder->addSelect($idColumn.' as id');
        $resultColumns = [];
        $outputColumns = [];
        foreach ($fieldKeys as $index => $fieldKey) {
            $column = $dataset->qualified($fieldKey);
            if ($fieldKey !== $key) {
                $builder->addSelect($column.' as '.$fieldKey);
            }
            $dimension = ResultColumn::dimension('d'.$index, new Dimension($fieldKey), $dataset);
            $resultColumns[] = $dimension;
            $referenceLabel = $dataset->labelColumnsFor($fieldKey)['label'] ?? null;
            if ($referenceLabel !== null && $fieldKey !== $key) {
                $builder->addSelect($referenceLabel.' as '.$fieldKey.'__label');
            }
            $filterField = $dataset->filterField($fieldKey);
            if ($dimension->labelKey === null) {
                $outputColumns[] = [
                    'key' => $fieldKey,
                    'caption' => $filterField->caption,
                    'type' => $filterField->type->value,
                ];
            } else {
                $outputColumns[] = [
                    'key' => $fieldKey,
                    'caption' => $filterField->caption,
                    'type' => $filterField->type->value,
                    'label_key' => $dimension->labelKey,
                ];
            }
        }

        $builder->orderBy($idColumn)->limit(self::PAGE_SIZE + 1);
        if ($cursor !== null) {
            $builder->where($idColumn, '>', $cursor);
        }
        $executed = $this->executor->run(new CompiledQuery($builder, null, [], self::PAGE_SIZE), $principal->timeoutMs());
        $hasMore = count($executed['rows']) > self::PAGE_SIZE;
        $rawRows = array_slice($executed['rows'], 0, self::PAGE_SIZE);
        $rows = array_map(static fn (object $row): array => get_object_vars($row), $rawRows);
        $rows = $this->labels->apply($dataset, $resultColumns, $rows, $principal);
        $recordRoute = $dataset->recordRoute;
        $rows = array_map(static function (array $row) use ($recordRoute): array {
            $id = (string) ($row['id'] ?? '');
            $row['id'] = $id;
            $row['record_url'] = $recordRoute === null ? null : str_replace('{id}', rawurlencode($id), $recordRoute);

            return $row;
        }, $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)]['id'];

        return [
            'columns' => $outputColumns,
            'rows' => $rows,
            'next_cursor' => $hasMore && is_string($last) ? $last : null,
            'record_route' => $recordRoute,
            'generated_at' => $principal->now()->toIso8601String(),
        ];
    }

    /** @param Builder<Model> $builder
     * @param  string|list<string>  $value
     */
    private function applyFilter(Builder $builder, CompiledDataset $dataset, string $field, string|array $value, AnalyticsPrincipal $principal): void
    {
        try {
            FieldFilterExpression::apply($builder, $dataset->filterField($field), $value, $principal->timezone());
        } catch (InvalidFilterExpression $exception) {
            throw AnalyticsQueryException::invalidFilter('filters.'.$field, $exception->getMessage(), $exception);
        }
    }

    /** @return string|list<string> */
    private function clickedFilter(CompiledDataset $dataset, Dimension $dimension, string|int|float|bool $value, string $timezone): string|array
    {
        if ($dimension->granularity !== null) {
            $period = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value, $timezone);
            if ($period === null) {
                throw AnalyticsQueryException::invalidQuery('values', 'Periode ini tidak dapat dibaca. Muat ulang dasbor.');
            }
            [$from, $to] = match ($dimension->granularity->value) {
                'day' => [$period, $period],
                'week' => [$period->startOfWeek(CarbonInterface::MONDAY), $period->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()],
                'month' => [$period->startOfMonth(), $period->endOfMonth()->startOfDay()],
                'quarter' => [$period->startOfQuarter(), $period->endOfQuarter()->startOfDay()],
                default => [$period->startOfYear(), $period->endOfYear()->startOfDay()],
            };

            return $from->toDateString().'..'.$to->toDateString();
        }

        $field = $dataset->filterField($dimension->field);
        if (in_array($field->type, [FieldType::Boolean, FieldType::Option, FieldType::Reference], true)) {
            return [(string) $value];
        }
        if ($field->type === FieldType::Text) {
            return "'".str_replace("'", "''", (string) $value)."'";
        }

        return (string) $value;
    }
}
