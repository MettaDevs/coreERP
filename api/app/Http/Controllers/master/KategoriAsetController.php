<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\KategoriAset;
use App\Support\MasterChild;
use App\Support\MasterParent;

class KategoriAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'kategori-aset';
    }

    protected function model(): string
    {
        return KategoriAset::class;
    }

    protected function parentMaster(): MasterParent
    {
        return new MasterParent(
            table: 'm_group_aset',
            column: 'group_aset_id',
            relation: 'groupAset',
            label: 'group aset',
        );
    }

    protected function childMasters(): array
    {
        return [new MasterChild(table: 'm_jenis_aset', column: 'kategori_aset_id', label: 'jenis aset')];
    }
}
