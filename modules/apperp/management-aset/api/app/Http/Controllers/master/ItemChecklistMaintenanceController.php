<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\ItemChecklistMaintenance;

class ItemChecklistMaintenanceController extends MasterDataController
{
    protected function resource(): string
    {
        return 'item-checklist-maintenance';
    }

    protected function model(): string
    {
        return ItemChecklistMaintenance::class;
    }
}
