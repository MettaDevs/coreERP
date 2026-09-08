<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistVariable;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Support\MasterChild;

class MaintenanceChecklistVariableController extends MasterDataController
{
    protected function resource(): string
    {
        return 'maintenance-checklist-variables';
    }

    protected function model(): string
    {
        return MaintenanceChecklistVariable::class;
    }

    protected function childMasters(): array
    {
        return [new MasterChild('aset_m_maintenance_checklist_variable_value', 'variable_id', 'nilai checklist')];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['values_count' => \DB::table('aset_m_maintenance_checklist_variable_value')->where(['tenant_id' => $record->tenant_id, 'variable_id' => $record->getKey()])->count()];
    }
}
