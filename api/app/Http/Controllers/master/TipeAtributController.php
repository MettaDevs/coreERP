<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\TipeAtribut;
use App\Models\MasterData;
use App\Support\MasterChild;
use Illuminate\Validation\Rule;

class TipeAtributController extends MasterDataController
{
    protected function resource(): string
    {
        return 'tipe-atribut';
    }

    protected function model(): string
    {
        return TipeAtribut::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'm_tipe_atribut_nilai', column: 'tipe_atribut_id', label: 'pilihan nilai'),
            new MasterChild(table: 'm_jenis_aset_atribut', column: 'tipe_atribut_id', label: 'atribut pada jenis aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'data_type' => [...$required, Rule::in(TipeAtribut::DATA_TYPES)],
            'satuan' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Batas hanya bermakna untuk tipe rentang nilai, dan wajib ada di sana.
            'min_value' => ['required_if:data_type,'.TipeAtribut::RANGE_TYPE, 'nullable', 'numeric'],
            'max_value' => ['required_if:data_type,'.TipeAtribut::RANGE_TYPE, 'nullable', 'numeric', 'gte:min_value'],
        ];
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['data_type', 'satuan', 'min_value', 'max_value'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return $record->only(['data_type', 'satuan', 'min_value', 'max_value']);
    }
}
