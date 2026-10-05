<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Actions\DrillDown;
use App\Platform\Analytics\Actions\DrillThrough;
use App\Platform\Analytics\Actions\DrillValues;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Dashboards\SlicerDefinitions;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Halaman baris di balik nilai, dibatasi hak yang sama dengan data widget. */
final class DrillController extends Controller
{
    public function __construct(
        private readonly DashboardAccess $dashboards,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryParser $parser,
        private readonly SlicerDefinitions $slicers,
        private readonly DrillThrough $drill,
        private readonly DrillDown $down,
        private readonly UserClock $clock,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'widget_id' => ['required', 'string', 'max:64'],
            'action' => ['sometimes', 'in:through,down'],
            'dimension_field' => ['required_if:action,down', 'string', 'max:64'],
            'values' => ['sometimes', 'required_if:action,down', 'array', 'list', 'max:20'],
            'values.*' => ['required', 'array:field,value,granularity'],
            'values.*.field' => ['required', 'string', 'max:64'],
            'values.*.value' => ['present'],
            'values.*.granularity' => ['nullable', 'in:day,week,month,quarter,year'],
            'cursor' => ['nullable', 'string', 'max:64'],
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
            $values = DrillValues::parse($input['values'] ?? []);
            if ($widget->dataset_code === null || $widget->query === null) {
                throw AnalyticsQueryException::invalidQuery('widget_id', 'Bagian ini tidak menampilkan data yang dapat dibuka.');
            }
            $dataset = $this->datasets->find($widget->dataset_code) ?? throw AnalyticsQueryException::datasetUnknown();
            $this->access->authorize($principal, $dataset);
            $read = StoredQuery::read($dataset, $widget->query, $widget->dataset_version);
            if ($read['missing'] !== []) {
                throw new AnalyticsQueryException('analytics.field_removed', 'Kolom yang dipakai bagian ini sudah tidak tersedia. Ubah bagian tersebut terlebih dahulu.', 422, 'widget_id');
            }
            $query = $this->parser->parse($read['query']);
            $locked = $this->slicers->filtersForWidget(
                $dashboard->slicers ?? [],
                $input['slicers'] ?? [],
                $input['cross_filters'] ?? [],
                $dataset,
                $read['query'],
                $principal,
            );
            $principal = UserPrincipal::fromMembership($membership, $this->clock->timezone($request), [$dataset->code => $locked]);
            if (($input['action'] ?? 'through') === 'down') {
                $result = $this->down->run($dataset, $query, $principal, $input['dimension_field'], $values);

                return response()->json([
                    'result' => $result['result']->toArray(),
                    'next' => $result['next'],
                    'path' => $values,
                ]);
            }
            $result = $this->drill->page($dataset, $query, $principal, $values, $widget->visual, $widget->type, $input['cursor'] ?? null);
        } catch (AnalyticsQueryException $exception) {
            return $exception->toResponse();
        }

        return response()->json($result);
    }
}
