<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\PabrikanAset;

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
}
