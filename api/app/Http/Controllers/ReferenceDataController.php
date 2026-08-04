<?php

namespace App\Http\Controllers;

use App\Services\UnitOfMeasureClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ReferenceDataController extends Controller
{
    public function unitsOfMeasure(Request $request, UnitOfMeasureClient $units): JsonResponse
    {
        abort_unless(in_array('management-aset.perencanaan-aset.read', $request->attributes->get('coreerp.permissions', []), true), 403);
        try {
            return response()->json(['data' => collect($units->active((string) $request->attributes->get('coreerp.tenant_id')))
                ->map(fn (array $unit): array => ['id' => $unit['id'], 'kode' => $unit['code'], 'nama' => $unit['name']])->values()]);
        }
        catch (RuntimeException $exception) { return response()->json(['error' => ['code' => 'units_of_measure_unavailable', 'message' => $exception->getMessage()]], 503); }
    }
}
