<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetCatalog;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\Dimension;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Identity\Support\UserClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Katalog data untuk layar (`GET api/v1/analytics/datasets` dan `.../datasets/{code}`), dijaga
 * `core.analytics.dashboard.read` di gate rute.
 *
 * Daftar hanya memuat dataset dari module yang terpasang dan berlisensi untuk tenant ini dan yang permission
 * baca resource-nya dipegang pengguna; isinya tanpa field dan measure data pribadi bagi yang tidak berhak.
 * Dataset yang tidak ada atau module-nya tidak terpasang dijawab 404 `analytics.dataset_unknown`, yang
 * permission-nya tidak dipegang 403 `analytics.dataset_forbidden` — urutan yang sama dengan query.
 */
final class DatasetController extends Controller
{
    public function __construct(
        private readonly DatasetCatalog $catalog,
        private readonly UserClock $clock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = UserPrincipal::fromMembership($this->currentMembership($request), $this->clock->timezone($request));

        return response()->json(['data' => array_map(
            fn (CompiledDataset $dataset): array => $this->catalog->summary($dataset, $principal),
            $this->catalog->forPrincipal($principal),
        )]);
    }

    public function show(Request $request, string $code, DatasetRegistry $datasets, DatasetAccess $access): JsonResponse
    {
        $principal = UserPrincipal::fromMembership($this->currentMembership($request), $this->clock->timezone($request));

        try {
            $dataset = $datasets->find($code) ?? throw AnalyticsQueryException::datasetUnknown();
            $access->authorize($principal, $dataset);
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $this->catalog->describe($dataset, $principal)]);
    }

    /** Pilihan unik dimensi bersama dari data yang benar-benar boleh dibaca principal. */
    public function fieldValues(Request $request, string $code, DatasetRegistry $datasets, DatasetAccess $access, RunQuery $run): JsonResponse
    {
        $principal = UserPrincipal::fromMembership($this->currentMembership($request), $this->clock->timezone($request));
        $field = $request->query('field');

        if (! is_string($field) || trim($field) === '' || mb_strlen($field) > 120) {
            return AnalyticsQueryException::invalidQuery('field', 'Pilih kolom bersama yang akan disaring.')->toResponse();
        }

        try {
            $dataset = $datasets->find($code) ?? throw AnalyticsQueryException::datasetUnknown();
            $access->authorize($principal, $dataset);

            if (! $dataset->hasField($field)) {
                throw AnalyticsQueryException::fieldUnknown('field', $field);
            }
            if ($dataset->sharedDimension($field) === null) {
                throw AnalyticsQueryException::invalidQuery('field', 'Kolom ini bukan kolom bersama.');
            }

            $result = $run->handle(
                $principal,
                new AnalyticsQuery(
                    dataset: $code,
                    dimensions: [new Dimension($field)],
                    measures: [],
                    filters: [],
                    timeRange: null,
                    sort: [['key' => $field, 'direction' => 'asc']],
                    limit: $principal->rowLimit(),
                    totals: false,
                    fillGaps: false,
                ),
                cacheTtl: QueryCache::EXPLORE_TTL_SECONDS,
                source: QueryLog::SOURCE_WIDGET,
            );
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        $options = [];
        foreach ($result->rows as $row) {
            $value = $row[$field] ?? null;
            if ($value === null || (string) $value === '') {
                continue;
            }

            $raw = (string) $value;
            $options[] = ['value' => $raw, 'label' => (string) ($row[$field.'__label'] ?? $raw)];
        }

        return response()->json(['data' => $options, 'truncated' => $result->meta['truncated']]);
    }
}
