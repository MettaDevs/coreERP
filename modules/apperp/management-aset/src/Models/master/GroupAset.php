<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Sumbu klasifikasi finansial aset; padanan "Fixed asset group" pada modul Fixed assets
 * Dynamics 365 F&O. Datar dan berdiri sendiri, tetapi membawa perlakuan akuntansi:
 * referensi kelompok harta fiskal, ambang kapitalisasi, dan lapisan pembukuan yang
 * diizinkan.
 */
class GroupAset extends MasterData
{
    /**
     * Apakah perolehan disajikan di neraca. `inventory_item` adalah barang yang tetap
     * diinventarisasi tetapi tidak dikapitalisasi; padanan `Continuing property` di F&O
     * dan barang ekstrakomptabel pada penatausahaan barang di Indonesia.
     *
     * Sifat harta — berwujud, tidak berwujud, hak guna — sengaja tidak disimpan di sini.
     * Klasifikasi itu menentukan akun, dan akun ditentukan posting profile milik Finance,
     * bukan modul ini. Aset yang perlu dibedakan sifatnya dibedakan dengan group sendiri.
     */
    public const PROPERTY_TYPE = ['fixed_asset', 'inventory_item', 'other'];

    protected $table = 'aset_m_group_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'kelompok_harta_fiskal_id', 'property_type', 'asset_location_id',
        'capitalization_threshold',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'capitalization_threshold' => 'decimal:2',
        ];
    }
}
