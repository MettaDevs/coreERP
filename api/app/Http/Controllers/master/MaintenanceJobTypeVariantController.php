<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\MaintenanceJobTypeVariant;
use App\Models\master\MaintenanceJobType;
use App\Support\MasterChild;
use App\Support\MasterParent;

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
