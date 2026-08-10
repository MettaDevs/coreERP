<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\GroupAset;
use App\Models\MasterData;
use App\Support\MasterChild;
use Illuminate\Validation\Rule;

class GroupAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'group-aset';
    }

    protected function model(): string
    {
        return GroupAset::class;
    }

    protected function childMasters(): array
    {
        // Setelah rantai klasifikasi diratakan, yang menggantung pada group bukan lagi
        // master lain melainkan aset itu sendiri: group membawa perlakuan finansial
        // yang dipakai aset, jadi mengarsipkannya selagi ada aset aktif akan memutus
        // dasar penyusutan aset tersebut.
        return [
            new MasterChild(table: 'tr_penerimaan_aset', column: 'group_aset_id', label: 'aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        return [
            'tipe_harta' => ['sometimes', 'nullable', Rule::in(GroupAset::TIPE_HARTA)],
            'major_type' => ['sometimes', 'nullable', Rule::in(GroupAset::MAJOR_TYPE)],
            'capitalization_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'posting_layers' => ['sometimes', 'nullable', 'array'],
            'posting_layers.*' => [Rule::in(GroupAset::POSTING_LAYERS)],
        ];
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['tipe_harta', 'major_type', 'capitalization_threshold'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }

        if (array_key_exists('posting_layers', $data)) {
            // Disimpan sebagai daftar dipisah koma; duplikat dibuang supaya dua kiriman
            // yang bermakna sama tidak tersimpan berbeda dan memicu konflik idempotency.
            $layers = array_values(array_unique($data['posting_layers'] ?? []));
            $payload['posting_layers'] = $layers === [] ? null : implode(',', $layers);
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return [
            'tipe_harta' => $record->tipe_harta,
            'major_type' => $record->major_type,
            'capitalization_threshold' => $record->capitalization_threshold,
            'posting_layers' => $record->posting_layers === null ? [] : explode(',', $record->posting_layers),
        ];
    }
}
