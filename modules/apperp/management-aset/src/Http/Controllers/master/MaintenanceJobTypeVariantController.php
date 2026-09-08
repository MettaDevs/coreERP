<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Support\MasterChild;
use Modules\Apperp\ManagementAset\Support\MasterParent;

class MaintenanceJobTypeVariantController extends MasterDataController
{
    protected function resource(): string
    {
        return 'maintenance-job-type-variants';
    }

    protected function model(): string
    {
        return MaintenanceJobTypeVariant::class;
    }

    protected function parentMasters(): array
    {
        return [new MasterParent('m_maintenance_job_type', 'maintenance_job_type_id', 'maintenanceJobType', 'Jenis pekerjaan maintenance')];
    }

    protected function childMasters(): array
    {
        return [new MasterChild('m_maintenance_job_type_default', 'variant_id', 'default job type')];
    }
}
