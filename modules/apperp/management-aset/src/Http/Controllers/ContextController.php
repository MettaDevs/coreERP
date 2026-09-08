<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Menyajikan hak akses efektif pada token konteks. Sengaja sebuah controller, bukan
 * closure, agar `php artisan route:cache` dapat dipakai pada image produksi.
 */
class ContextController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'permissions' => $request->attributes->get('coreerp.permissions', []),
                'legal_entity_id' => $request->attributes->get('coreerp.legal_entity_id'),
                'org_unit_id' => $request->attributes->get('coreerp.org_unit_id'),
                'user_id' => $request->attributes->get('coreerp.user_id'),
            ],
        ]);
    }
}
