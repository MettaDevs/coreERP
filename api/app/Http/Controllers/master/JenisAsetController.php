<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\JenisAset;
use App\Support\MasterChild;
use App\Support\MasterParent;

class JenisAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'jenis-aset';
    }

    protected function model(): string
    {
        return JenisAset::class;
    }

    protected function parentMaster(): MasterParent
    {
        return new MasterParent(
            table: 'm_kategori_aset',
            column: 'kategori_aset_id',
            relation: 'kategoriAset',
            label: 'kategori aset',
        );
    }

    protected function childMasters(): array
    {
        return [new MasterChild(table: 'm_entitas_aset', column: 'jenis_aset_id', label: 'entitas aset')];
    }
}
