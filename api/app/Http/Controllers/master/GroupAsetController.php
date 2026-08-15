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
            // Klasifikasi fiskal adalah reference data berversi. Validasi hanya
            // memastikan ID aktif milik tenant yang sama; daftar nilainya bukan enum PHP.
            'kelompok_harta_fiskal_id' => [
                'sometimes', 'nullable', 'ulid',
                Rule::exists('m_kelompok_harta_fiskal', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->where('aktif', true),
            ],
            // Tolak nama lama secara eksplisit supaya client lama tidak diam-diam
            // kehilangan klasifikasi ketika beralih ke reference ID.
            'tipe_harta' => ['prohibited'],
            // Sifat harta dibuang dari group: ia tidak menggerakkan apa pun di sini, dan
            // akun ditentukan posting profile milik Finance. Menolaknya lebih baik
            // daripada menerima diam-diam, karena client lama yang mengirim `low_value`
            // sebenarnya bermaksud menandai barang non-kapitalisasi — maksud yang
            // sekarang hanya terekam benar lewat `property_type`.
            'major_type' => ['prohibited'],
            'property_type' => ['sometimes', 'nullable', Rule::in(GroupAset::PROPERTY_TYPE)],
            // Lokasi bawaan; hanya nilai awal saat aset diterima, bukan lokasi yang berlaku.
            'asset_location_id' => [
                'sometimes', 'nullable', 'ulid',
                Rule::exists('m_lokasi_aset', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'capitalization_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'posting_layers' => ['sometimes', 'nullable', 'array'],
            'posting_layers.*' => [Rule::in(GroupAset::POSTING_LAYERS)],
        ];
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['kelompok_harta_fiskal_id', 'property_type', 'asset_location_id', 'capitalization_threshold'] as $column) {
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
            'kelompok_harta_fiskal_id' => $record->kelompok_harta_fiskal_id,
            'property_type' => $record->property_type,
            'asset_location_id' => $record->asset_location_id,
            'capitalization_threshold' => $record->capitalization_threshold,
            'posting_layers' => $record->posting_layers === null ? [] : explode(',', $record->posting_layers),
        ];
    }
}
