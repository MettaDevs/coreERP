<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\AnalisaMaintenance;

class AnalisaMaintenanceController extends MasterDataController
{
    protected function resource(): string
    {
        return 'analisa-maintenance';
    }

    protected function model(): string
    {
        return AnalisaMaintenance::class;
    }
}
