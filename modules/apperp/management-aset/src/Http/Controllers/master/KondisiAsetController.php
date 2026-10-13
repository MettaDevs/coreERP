<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Support\AssetConditionPolicy;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * @extends MasterDataController<KondisiAset>
 */
class KondisiAsetController extends MasterDataController
{
    protected function prepareQuery(Builder $query, ?Request $request = null): Builder
    {
        if ($request === null || ! $request->isMethod('GET') || $request->route('id') !== null) {
            return $query;
        }
        $data = $request->validate(['jenis_aset_id' => ['nullable', 'ulid'], 'aset_id' => ['nullable', 'ulid']]);
        $assetTypeId = $data['jenis_aset_id'] ?? null;
        if (! empty($data['aset_id'])) {
            $assets = Aset::query();
            app(OrganizationScope::class)->asetQuery($assets, $request);
            $assetTypeId = $assets->findOrFail($data['aset_id'])->jenis_aset_id;
        } elseif ($assetTypeId !== null) {
            JenisAset::query()->findOrFail($assetTypeId);
        }

        return AssetConditionPolicy::choices($assetTypeId);
    }

    protected function resource(): string
    {
        return 'kondisi-aset';
    }

    protected function model(): string
    {
        return KondisiAset::class;
    }
}
