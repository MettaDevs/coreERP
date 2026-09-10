<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Support\MasterChild;

/**
 * @extends MasterDataController<MaintenanceJobType>
 */
class MaintenanceJobTypeController extends MasterDataController
{
    protected function resource(): string
    {
        return 'maintenance-job-types';
    }

    protected function model(): string
    {
        return MaintenanceJobType::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild('aset_m_maintenance_job_type_variant', 'maintenance_job_type_id', 'varian job type'),
            new MasterChild('aset_m_maintenance_job_type_default', 'maintenance_job_type_id', 'default job type'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        return [
            'category_code' => ['sometimes', 'string', 'max:40', Rule::in(['preventive', 'corrective', 'service', 'condition_assessment'])],
            'maintenance_downtime_activities' => ['sometimes', 'boolean'],
        ];
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        if (array_key_exists('category_code', $data)) {
            $payload['category_code'] = $data['category_code'];
        }
        if (array_key_exists('maintenance_downtime_activities', $data)) {
            $payload['maintenance_downtime_activities'] = filter_var($data['maintenance_downtime_activities'], FILTER_VALIDATE_BOOL);
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return [
            'category_code' => $record->category_code,
            'maintenance_downtime_activities' => (bool) $record->maintenance_downtime_activities,
            'variants_count' => $record->newQuery()->where('tenant_id', $record->tenant_id)->whereKey($record->getKey())->withCount(['variants'])->value('variants_count') ?? 0,
        ];
    }
}
