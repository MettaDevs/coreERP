<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\GroupAset;
use App\Support\MasterChild;

class GroupAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'group-aset';
    }

    protected function model(): string
    {
        return GroupAset::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'm_kategori_aset', column: 'group_aset_id', label: 'kategori aset'),
        ];
    }
}
