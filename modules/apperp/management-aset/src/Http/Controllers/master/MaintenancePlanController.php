<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlan;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Header rencana pemeliharaan preventif; padanan *Maintenance plan* F&O.
 *
 * Baris dan objek rencana disunting lewat {@see MaintenancePlanDetailController}, dengan versi header
 * ini sebagai penjaga edit bersamaan.
 *
 * @extends MasterDataController<MaintenancePlan>
 */
class MaintenancePlanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'rencana-pemeliharaan';
    }

    protected function model(): string
    {
        return MaintenancePlan::class;
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'tanggal_mulai' => [...$required, 'date'],
            'toleransi_hari_sebelum' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'toleransi_hari_sesudah' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ];
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        if (array_key_exists('tanggal_mulai', $data)) {
            $payload['tanggal_mulai'] = $data['tanggal_mulai'];
        }
        foreach (['toleransi_hari_sebelum', 'toleransi_hari_sesudah'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = (int) $data[$column];
            }
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return [
            'tanggal_mulai' => $record->tanggal_mulai->toDateString(),
            'toleransi_hari_sebelum' => $record->toleransi_hari_sebelum,
            'toleransi_hari_sesudah' => $record->toleransi_hari_sesudah,
        ];
    }
}
