<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\Controller;
use App\Support\AssetAttributeValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Definisi atribut satu jenis aset, siap dipakai form penerimaan aset untuk merender
 * field dinamisnya. Dibaca dengan permission jenis aset karena isinya memang milik
 * master jenis, bukan data transaksi.
 */
class JenisAsetAtributDefinisiController extends Controller
{
    public function __invoke(Request $request, string $jenisAsetId, AssetAttributeValidator $attributes): JsonResponse
    {
        abort_unless(
            in_array('management-aset.jenis-aset.read', $request->attributes->get('coreerp.permissions', []), true),
            403
        );

        return response()->json([
            'data' => $attributes->definitions(
                (string) $request->attributes->get('coreerp.tenant_id'),
                $jenisAsetId,
            ),
        ]);
    }
}
