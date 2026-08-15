<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\MasterData;
use App\Models\master\MaintenanceChecklistTemplate;
use App\Support\MasterChild;

class MaintenanceChecklistTemplateController extends MasterDataController
{
    protected function resource(): string
    {
        return 'maintenance-checklist-templates';
    }

    protected function model(): string
    {
        return MaintenanceChecklistTemplate::class;
    }

    protected function childMasters(): array
    {
        return [new MasterChild('m_maintenance_checklist_template_line', 'template_id', 'baris checklist')];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['checks_count' => \DB::table('m_maintenance_checklist_template_line')->where(['tenant_id' => $record->tenant_id, 'template_id' => $record->getKey()])->count()];
    }
}
