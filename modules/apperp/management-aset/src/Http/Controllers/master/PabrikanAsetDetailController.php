<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\master\PabrikanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Angka turunan untuk panel detail pabrikan. Angka ini sengaja dipisahkan dari
 * respons master agar daftar pabrikan tidak menghitung apa pun dan setiap angka
 * tetap menegakkan izin resource yang dihitung.
 */
class PabrikanAsetDetailController extends Controller
{
    public function __invoke(Request $request, string $pabrikanAsetId, OrganizationScope $scope): JsonResponse
    {
        $permissions = $request->attributes->get('coreerp.permissions', []);
        abort_unless(in_array('management-aset.pabrikan-aset.read', $permissions, true), 403);

        abort_unless(PabrikanAset::query()->whereKey($pabrikanAsetId)->exists(), 404);

        $mayReadModels = in_array('management-aset.model-aset.read', $permissions, true);
        $mayReadAssets = in_array('management-aset.aset.read', $permissions, true);

        return response()->json(['data' => [
            'model_count' => $mayReadModels
                ? ModelAset::query()
                    ->where('pabrikan_aset_id', $pabrikanAsetId)
                    ->count()
                : null,
            'asset_count' => $mayReadAssets
                ? $scope->assetQuery(
                    Asset::query()
                        ->where('pabrikan_aset_id', $pabrikanAsetId)
                        ->whereNotIn('lifecycle_state', ['decommissioned', 'disposed']),
                    $request,
                )->count()
                : null,
        ]]);
    }
}
