<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\MaintenanceChecklistVariable;
use App\Models\MasterData;
use App\Support\MasterChild;

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
        return [new MasterChild('m_maintenance_checklist_variable_value', 'variable_id', 'nilai checklist')];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['values_count' => \DB::table('m_maintenance_checklist_variable_value')->where(['tenant_id' => $record->tenant_id, 'variable_id' => $record->getKey()])->count()];
    }
}
