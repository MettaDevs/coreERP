<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
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

        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        abort_unless(
            $this->unarchived('aset_m_pabrikan_aset')
                ->where('tenant_id', $tenantId)
                ->where('id', $pabrikanAsetId)
                ->exists(),
            404,
        );

        $mayReadModels = in_array('management-aset.model-aset.read', $permissions, true);
        $mayReadAssets = in_array('management-aset.aset.read', $permissions, true);

        return response()->json(['data' => [
            'model_count' => $mayReadModels
                ? $this->unarchived('aset_m_model_aset')
                    ->where('tenant_id', $tenantId)
                    ->where('pabrikan_aset_id', $pabrikanAsetId)
                    ->count()
                : null,
            'asset_count' => $mayReadAssets
                ? $scope->assetQuery(
                    $this->unarchived('aset_tr_penerimaan_aset')
                        ->where('tenant_id', $tenantId)
                        ->where('pabrikan_aset_id', $pabrikanAsetId)
                        ->whereNotIn('lifecycle_state', ['decommissioned', 'disposed']),
                    $request,
                )->count()
                : null,
        ]]);
    }

    /**
     * Baris terarsip tidak ikut dihitung. Semua tabel yang dipakai endpoint ini
     * mengenal soft delete, tetapi pemeriksaan membuat helper tetap aman bila
     * dipakai pada tabel master yang belum memiliki kolom tersebut.
     */
    private function unarchived(string $table): Builder
    {
        $query = DB::table($table);

        return $query->whereNull($table.'.deleted_at');
    }
}
