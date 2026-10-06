<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\Blend;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pratinjau dua query mandiri yang dikelompokkan menurut dimensi bersama (area 14). */
final class BlendController extends Controller
{
    public function __invoke(Request $request, Blend $blend, UserClock $clock): JsonResponse
    {
        $principal = UserPrincipal::fromMembership($this->currentMembership($request), $clock->timezone($request));

        try {
            $result = $blend->handle($principal, $request->json()->all(), cacheTtl: QueryCache::EXPLORE_TTL_SECONDS);
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return response()->json($result->toArray());
    }
}
