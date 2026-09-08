<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\SebabKerusakan;
use Modules\Apperp\ManagementAset\Models\MasterData;

class SebabKerusakanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'sebab-kerusakan';
    }

    protected function model(): string
    {
        return SebabKerusakan::class;
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        return ['minta_keterangan' => ['sometimes', 'boolean']];
    }

    protected function extraPayload(array $data): array
    {
        return array_key_exists('minta_keterangan', $data)
            ? ['minta_keterangan' => filter_var($data['minta_keterangan'], FILTER_VALIDATE_BOOL)]
            : [];
    }

    protected function extraPresent(MasterData $record): array
    {
        return ['minta_keterangan' => (bool) $record->minta_keterangan];
    }
}
