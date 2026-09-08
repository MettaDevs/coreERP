<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use App\Support\Modules\Contracts\KonteksPermintaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Menyajikan hak akses efektif pengguna yang sedang meminta.
 *
 * Sumbernya sekarang `KonteksPermintaan` milik Core, bukan klaim pada token JWT. Bentuk
 * jawabannya sengaja tidak berubah sedikit pun: UI module membacanya apa adanya, dan mengubah
 * bentuknya di sini berarti mengubah UI pada pull request yang seharusnya hanya memindahkan
 * sumber datanya.
 *
 * Sengaja sebuah controller, bukan closure, supaya `route:cache` tetap bisa dipakai.
 *
 * Controller ini dihapus pada F4-07, setelah UI module berhenti memanggilnya.
 */
class ContextController extends Controller
{
    public function __invoke(Request $request, KonteksPermintaan $konteks): JsonResponse
    {
        return response()->json([
            'data' => [
                'permissions' => $konteks->izin(),
                'legal_entity_id' => $request->attributes->get('coreerp.legal_entity_id'),
                'org_unit_id' => $request->attributes->get('coreerp.org_unit_id'),
                'user_id' => $konteks->penggunaId(),
            ],
        ]);
    }
}
