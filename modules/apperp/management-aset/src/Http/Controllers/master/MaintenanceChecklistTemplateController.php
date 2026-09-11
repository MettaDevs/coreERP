<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplate;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplateLine;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Support\MasterChild;

/**
 * @extends MasterDataController<MaintenanceChecklistTemplate>
 */
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
        return [new MasterChild('aset_m_maintenance_checklist_template_line', 'template_id', 'baris checklist')];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['checks_count' => MaintenanceChecklistTemplateLine::query()->where('template_id', $record->getKey())->count()];
    }
}
