<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/analytics/query`: query bebas dari layar Core, dijalankan sebagai pengguna yang meminta.
 *
 * Hak: keanggotaan tenant (403 tanpa keanggotaan) dan permission baca resource dataset, diperiksa
 * {@see RunQuery}. Permission analitik sendiri (KA-14) belum ada; sampai disetujui, rutenya di balik
 * saklar `analytics.enabled`. Bentuk query dan hasilnya di `docs/todo/analitik/mesin-query.md`.
 */
final class QueryController extends Controller
{
    public function __invoke(Request $request, QueryParser $parser, RunQuery $run, UserClock $clock): JsonResponse
    {
        $principal = UserPrincipal::fromMembership($this->currentMembership($request), $clock->timezone($request));

        try {
            $result = $run->handle($principal, $parser->parse($request->json()->all()));
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return response()->json($result->toArray());
    }
}
