<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizationDirectoryController extends Controller
{
    public function operatingUnits(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('coreerp.app_id') === 'human-resources', 403);

        $organizations = Organization::query()
            ->where('tenant_id', $request->attributes->get('coreerp.tenant_id'))
            ->where('classification', 'operating_unit')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $organizations]);
    }
}
