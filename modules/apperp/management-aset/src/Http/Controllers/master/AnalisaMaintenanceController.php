<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\AnalisaMaintenance;

/**
 * @extends MasterDataController<AnalisaMaintenance>
 */
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
