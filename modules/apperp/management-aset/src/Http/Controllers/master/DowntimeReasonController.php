<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\DowntimeReason;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Alasan downtime; padanan *Maintenance downtime reason code* Dynamics 365 F&O, beserta *KPI include*.
 *
 * @extends MasterDataController<DowntimeReason>
 */
class DowntimeReasonController extends MasterDataController
{
    protected function resource(): string
    {
        return 'alasan-downtime';
    }

    protected function model(): string
    {
        return DowntimeReason::class;
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        return ['masuk_kpi' => ['sometimes', 'boolean']];
    }

    protected function extraPayload(array $data): array
    {
        return array_key_exists('masuk_kpi', $data)
            ? ['masuk_kpi' => filter_var($data['masuk_kpi'], FILTER_VALIDATE_BOOL)]
            : [];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['masuk_kpi' => (bool) $record->masuk_kpi];
    }
}
