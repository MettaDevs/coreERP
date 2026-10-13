<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Actions\DrillValues;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Dashboards\SlicerDefinitions;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\RelativeRange;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Reporting\Support\ExportQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Memasukkan hasil widget atau daftar drill ke antrean ekspor Core. */
final class AnalyticsExportController extends Controller
{
    public function __construct(
        private readonly DashboardAccess $dashboards,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryParser $parser,
        private readonly QueryNormalizer $normalizer,
        private readonly QueryValidator $validator,
        private readonly SlicerDefinitions $slicers,
        private readonly ExportQueue $exports,
        private readonly CurrentWorkspace $workspace,
        private readonly UserClock $clock,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'widget_id' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'in:widget,drill'],
            'values' => ['sometimes', 'array', 'list', 'max:20'],
            'values.*' => ['required', 'array:field,value,granularity'],
            'values.*.field' => ['required', 'string', 'max:64'],
            'values.*.value' => ['present'],
            'values.*.granularity' => ['nullable', 'in:day,week,month,quarter,year'],
            'slicers' => ['nullable', 'array'],
            'cross_filters' => ['nullable', 'array'],
        ]);
        $membership = $this->currentMembership($request);
        $widget = Widget::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereHas('dashboard', fn ($query) => $query->where('tenant_id', $membership->tenant_id))
            ->whereKey($input['widget_id'])
            ->firstOrFail();
        $dashboard = $widget->dashboard ?? abort(404);
        $this->dashboards->authorizeView($membership, $dashboard);
        $principal = UserPrincipal::fromMembership($membership, $this->clock->timezone($request));

        try {
            if ($widget->dataset_code === null || $widget->query === null) {
                throw AnalyticsQueryException::invalidQuery('widget_id', 'Bagian ini tidak memiliki data untuk diekspor.');
            }
            $dataset = $this->datasets->find($widget->dataset_code) ?? throw AnalyticsQueryException::datasetUnknown();
            $this->access->authorize($principal, $dataset);
            $read = StoredQuery::read($dataset, $widget->query, $widget->dataset_version);
            if ($read['missing'] !== []) {
                throw new AnalyticsQueryException('analytics.field_removed', 'Kolom yang dipakai bagian ini sudah tidak tersedia. Ubah bagian tersebut terlebih dahulu.', 422, 'widget_id');
            }
            $query = $this->normalizer->normalize($this->parser->parse($read['query']));
            $this->validator->validate($dataset, $query, $principal);
            $locked = $this->slicers->filtersForWidget(
                $dashboard->slicers ?? [],
                $input['slicers'] ?? [],
                $input['cross_filters'] ?? [],
                $dataset,
                $read['query'],
                $principal,
            );
            $values = DrillValues::parse($input['values'] ?? []);
            $this->assertDrillValues($input['kind'], $values, $query, $dataset);
        } catch (AnalyticsQueryException $exception) {
            return $exception->toResponse();
        }

        $queryData = $query->normalized();
        if ($query->timeRange !== null) {
            $queryData['time_range']['range'] = RelativeRange::expression($query->timeRange->range, $principal->now());
        }
        $parameters = [
            'export_type' => $input['kind'],
            'query' => $queryData,
            'values' => $values,
            'locked_filters' => $locked,
            'visual' => $widget->visual,
            'type' => $widget->type,
            'cache_ttl_seconds' => $widget->cache_ttl_seconds,
        ];
        $export = $this->exports->enqueueAnalytics(
            $dataset->moduleId,
            $dataset->code,
            $widget->title,
            $membership,
            $this->workspace->legalEntity($request, $membership)?->id,
            $this->workspace->operatingUnit($request, $membership)?->id,
            $parameters,
        );

        return response()->json(['data' => $export], 202, ['Location' => url('/api/v1/report-exports/'.$export['id'])]);
    }

    /** @param list<array{field: string, value: scalar|null, granularity?: string|null}> $values */
    private function assertDrillValues(string $kind, array $values, AnalyticsQuery $query, CompiledDataset $dataset): void
    {
        if ($kind === 'widget') {
            if ($values !== []) {
                throw AnalyticsQueryException::invalidQuery('values', 'Ekspor bagian tidak menerima pilihan baris.');
            }

            return;
        }

        $dimensions = [];
        foreach ($query->dimensions as $dimension) {
            $dimensions[$dimension->field] = $dimension->granularity?->value;
        }
        $allowed = array_fill_keys(array_keys($dimensions), true);
        foreach ($dataset->hierarchies() as $levels) {
            if (isset($dimensions[$levels[0] ?? ''])) {
                foreach ($levels as $field) {
                    $allowed[$field] = true;
                }
            }
        }
        foreach ($values as $index => $value) {
            if (! isset($allowed[$value['field']]) || ! $dataset->hasField($value['field'])
                || (($value['granularity'] ?? null) !== null && ! in_array($value['field'], $dataset->times(), true))
                || (($value['granularity'] ?? null) !== null && ! in_array($value['granularity'], ['day', 'week', 'month', 'quarter', 'year'], true))) {
                throw AnalyticsQueryException::invalidQuery("values.{$index}", 'Pilihan baris ini tidak cocok dengan bagian. Muat ulang dasbor.');
            }
        }
    }
}
