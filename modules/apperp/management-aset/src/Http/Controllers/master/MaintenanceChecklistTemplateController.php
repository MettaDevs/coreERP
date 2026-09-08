<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplate;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Support\MasterChild;

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
