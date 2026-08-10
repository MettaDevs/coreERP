<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\PabrikanAset;
use App\Support\MasterChild;

class PabrikanAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'pabrikan-aset';
    }

    protected function model(): string
    {
        return PabrikanAset::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'm_model_aset', column: 'pabrikan_aset_id', label: 'model aset'),
            new MasterChild(table: 'tr_penerimaan_aset', column: 'pabrikan_aset_id', label: 'aset'),
        ];
    }
}
