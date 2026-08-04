<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\KondisiAset;

class KondisiAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'kondisi-aset';
    }

    protected function model(): string
    {
        return KondisiAset::class;
    }
}
