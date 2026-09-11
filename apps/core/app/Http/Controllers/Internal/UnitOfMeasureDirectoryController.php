<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\ConvertUnitsRequest;
use App\Http\Requests\ReferenceData\ResolveUnitsRequest;
use App\Models\UnitOfMeasure;
use App\Services\UnitOfMeasureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UnitOfMeasureDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = UnitOfMeasure::query()->where('tenant_id', $request->attributes->get('coreerp.tenant_id'))->where('active', true)
            ->orderBy('name')->get(['id', 'code', 'name', 'symbol', 'decimal_places']);

        return response()->json(['data' => $data]);
    }

    public function resolve(ResolveUnitsRequest $request, UnitOfMeasureService $service): JsonResponse
    {
        return response()->json(['data' => array_values($service->resolve((string) $request->attributes->get('coreerp.tenant_id'), $request->validated('unit_ids')))]);
    }

    public function convert(ConvertUnitsRequest $request, UnitOfMeasureService $service): JsonResponse
    {
        return response()->json(['data' => $service->convert((string) $request->attributes->get('coreerp.tenant_id'), $request->string('from_unit_id')->toString(), $request->string('to_unit_id')->toString(), $request->string('value')->toString())]);
    }
}
