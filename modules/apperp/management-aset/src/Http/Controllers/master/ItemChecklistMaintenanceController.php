<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\ItemChecklistMaintenance;

/**
 * @extends MasterDataController<ItemChecklistMaintenance>
 */
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
