<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Support\MasterChild;

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
            new MasterChild(table: 'aset_tr_penerimaan_aset', column: 'group_aset_id', label: 'aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        return [
            // Klasifikasi fiskal adalah reference data berversi. Validasi hanya
            // memastikan ID aktif milik tenant yang sama; daftar nilainya bukan enum PHP.
            'kelompok_harta_fiskal_id' => [
                'sometimes', 'nullable', 'ulid',
                Rule::exists('aset_m_kelompok_harta_fiskal', 'id')
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
            // Lapisan pembukuan adalah sifat buku, bukan sifat group; tempatnya di
            // `aset_m_buku_penyusutan.posting_layer`, sama seperti Book di F&O. Selama ada di
            // sini kolomnya tidak pernah dibaca untuk apa pun, sehingga konfigurator
            // mengisinya lalu menyangka sudah mengatur sesuatu. Menolaknya menunjukkan
            // tempat yang benar, bukan menelan kiriman yang tidak berefek.
            'posting_layers' => ['prohibited'],
            'property_type' => ['sometimes', 'nullable', Rule::in(GroupAset::PROPERTY_TYPE)],
            // Lokasi bawaan; hanya nilai awal saat aset diterima, bukan lokasi yang berlaku.
            'asset_location_id' => [
                'sometimes', 'nullable', 'ulid',
                Rule::exists('aset_m_lokasi_aset', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'capitalization_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
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

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return [
            'kelompok_harta_fiskal_id' => $record->kelompok_harta_fiskal_id,
            'property_type' => $record->property_type,
            'asset_location_id' => $record->asset_location_id,
            'capitalization_threshold' => $record->capitalization_threshold,
        ];
    }
}
