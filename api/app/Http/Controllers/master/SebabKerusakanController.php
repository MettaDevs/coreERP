<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\SebabKerusakan;

class SebabKerusakanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'sebab-kerusakan';
    }

    protected function model(): string
    {
        return SebabKerusakan::class;
    }
}
