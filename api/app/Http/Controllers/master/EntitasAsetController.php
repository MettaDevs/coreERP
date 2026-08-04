<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\EntitasAset;
use App\Support\MasterParent;

class EntitasAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'entitas-aset';
    }

    protected function model(): string
    {
        return EntitasAset::class;
    }

    protected function parentMaster(): MasterParent
    {
        return new MasterParent(
            table: 'm_jenis_aset',
            column: 'jenis_aset_id',
            relation: 'jenisAset',
            label: 'jenis aset',
        );
    }
}
