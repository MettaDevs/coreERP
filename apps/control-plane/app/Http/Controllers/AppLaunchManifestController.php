<?php

namespace App\Http\Controllers;

use App\Support\CurrentWorkspace;
use App\Support\LaunchableAppCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppLaunchManifestController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $workspace, LaunchableAppCatalog $apps): JsonResponse
    {
        $membership = $workspace->membership($request);
        abort_unless($membership, 403);

        return response()->json([
            'data' => [
                'tenant' => ['id' => $membership->tenant_id, 'name' => $membership->tenant->name],
                'apps' => collect($apps->for($membership))->map(fn (array $app): array => [
                    ...$app,
                    'entry' => $app['href'],
                ])->values(),
            ],
        ]);
    }
}
