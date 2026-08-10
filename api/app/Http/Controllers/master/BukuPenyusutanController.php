<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\BukuPenyusutan;
use App\Models\MasterData;
use App\Support\MasterChild;
use Illuminate\Validation\Rule;

class BukuPenyusutanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'buku-penyusutan';
    }

    protected function model(): string
    {
        return BukuPenyusutan::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'm_group_buku_penyusutan', column: 'buku_id', label: 'baris matriks group'),
            new MasterChild(table: 'tr_buku_aset', column: 'buku_id', label: 'buku aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $profileExists = Rule::exists('m_profil_penyusutan', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return [
            'posting_layer' => ['sometimes', Rule::in(BukuPenyusutan::POSTING_LAYERS)],
            'export_to_backoffice' => ['sometimes', 'boolean'],
            'round_off_depreciation' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'depreciation_profile_id' => ['sometimes', 'nullable', 'ulid', $profileExists],
            'alternative_profile_id' => ['sometimes', 'nullable', 'ulid', $profileExists],
        ];
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['posting_layer', 'round_off_depreciation', 'depreciation_profile_id', 'alternative_profile_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }
        if (array_key_exists('export_to_backoffice', $data)) {
            // Dinormalkan ke boolean asli agar replay() membandingkan nilai setipe.
            $payload['export_to_backoffice'] = filter_var($data['export_to_backoffice'], FILTER_VALIDATE_BOOL);
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return $record->only(['posting_layer', 'export_to_backoffice', 'round_off_depreciation', 'depreciation_profile_id', 'alternative_profile_id']);
    }
}
