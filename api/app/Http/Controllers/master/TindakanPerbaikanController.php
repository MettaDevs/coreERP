<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\TindakanPerbaikan;

class TindakanPerbaikanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'tindakan-perbaikan';
    }

    protected function model(): string
    {
        return TindakanPerbaikan::class;
    }
}
