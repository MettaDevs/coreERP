<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/analytics/query`: query bebas dari layar Core, dijalankan sebagai pengguna yang meminta.
 *
 * Hak: `core.analytics.explore.invoke` di gate rute (KA-14), lalu module dataset terpasang dan permission
 * baca resource dataset, diperiksa {@see RunQuery}. Bentuk query dan hasilnya di
 * `docs/todo/analitik/mesin-query.md`.
 *
 * Rute ini dibatasi limiter `analytics-interactive` per pengguna (area 9). Hasilnya di-cache satu menit
 * ({@see QueryCache::EXPLORE_TTL_SECONDS}): query penjelajah berubah tiap klik, tetapi hasil yang sama persis
 * dalam satu menit tidak dihitung ulang.
 */
final class QueryController extends Controller
{
    public function __invoke(Request $request, QueryParser $parser, RunQuery $run, UserClock $clock): JsonResponse
    {
        $principal = UserPrincipal::fromMembership($this->currentMembership($request), $clock->timezone($request));

        try {
            $result = $run->handle($principal, $parser->parse($request->json()->all()), cacheTtl: QueryCache::EXPLORE_TTL_SECONDS);
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return response()->json($result->toArray());
    }
}
