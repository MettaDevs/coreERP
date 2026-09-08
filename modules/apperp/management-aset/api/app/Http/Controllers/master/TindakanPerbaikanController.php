<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\TindakanPerbaikan;
use App\Models\MasterData;

class TindakanPerbaikanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'tindakan-perbaikan';
    }

    protected function model(): string
    {
        return TindakanPerbaikan::class;
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
