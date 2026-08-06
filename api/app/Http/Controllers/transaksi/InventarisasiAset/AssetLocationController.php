<?php

namespace App\Http\Controllers\transaksi\InventarisasiAset;

use App\Http\Controllers\MasterDataController;
use App\Models\transaksi\InventarisasiAset\AssetLocation;
use App\Support\MasterChild;
use App\Support\MasterParent;

class AssetLocationController extends MasterDataController
{
    protected function resource(): string
    {
        return 'lokasi-aset';
    }

    protected function model(): string
    {
        return AssetLocation::class;
    }

    protected function parentMaster(): ?MasterParent
    {
        return new MasterParent(table: 'm_lokasi_aset', column: 'parent_id', relation: 'parentLocation', label: 'Lokasi induk', required: false);
    }

    protected function childMasters(): array
    {
        return [new MasterChild(table: 'm_lokasi_aset', column: 'parent_id', label: 'lokasi anak')];
    }
}
