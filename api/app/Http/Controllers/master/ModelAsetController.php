<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\ModelAset;
use App\Support\MasterChild;
use App\Support\MasterParent;
use App\Support\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Katalog model barang per pabrikan. Master pertama dengan dua induk yang saling
 * lepas: pabrikan wajib, jenis opsional, dan tidak ada penyaringan bertingkat di
 * antara keduanya.
 */
class ModelAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'model-aset';
    }

    protected function model(): string
    {
        return ModelAset::class;
    }

    protected function parentMasters(): array
    {
        return [
            new MasterParent(
                table: 'm_pabrikan_aset',
                column: 'pabrikan_aset_id',
                relation: 'pabrikanAset',
                label: 'pabrikan aset',
            ),
            new MasterParent(
                table: 'm_jenis_aset',
                column: 'jenis_aset_id',
                relation: 'jenisAset',
                label: 'jenis aset',
                required: false,
            ),
        ];
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'tr_penerimaan_aset', column: 'model_aset_id', label: 'aset'),
        ];
    }

    /**
     * Jumlah aset dipakai oleh grid gabungan pabrikan-model. Ia dihitung sebagai
     * subquery terkorlasi agar satu permintaan daftar tidak membuat query per model,
     * dan tetap dilewatkan ke policy organisasi aset.
     */
    protected function prepareQuery(Builder $query, ?Request $request = null): Builder
    {
        if (! $request || ! in_array('management-aset.aset.read', $request->attributes->get('coreerp.permissions', []), true)) {
            return $query;
        }

        $assets = DB::table('tr_penerimaan_aset as asset')
            ->whereColumn('asset.tenant_id', 'm_model_aset.tenant_id')
            ->whereColumn('asset.model_aset_id', 'm_model_aset.id')
            ->whereNull('asset.deleted_at')
            ->whereNotIn('asset.lifecycle_state', ['decommissioned', 'disposed']);

        $assets = app(OrganizationScope::class)->assetQuery($assets, $request, 'asset');

        return $query->addSelect([
            'asset_count' => $assets->selectRaw('count(*)'),
        ]);
    }

    protected function extraPresent(\App\Models\MasterData $record): array
    {
        return [
            'asset_count' => $record->getAttribute('asset_count') === null
                ? null
                : (int) $record->getAttribute('asset_count'),
        ];
    }
}
