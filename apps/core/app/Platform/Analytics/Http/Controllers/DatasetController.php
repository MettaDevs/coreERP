<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetCatalog;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
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
}
