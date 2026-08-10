<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Sumbu klasifikasi finansial aset; padanan "Fixed asset group" pada modul Fixed assets
 * Dynamics 365 F&O. Datar dan berdiri sendiri, tetapi membawa perlakuan akuntansi:
 * kelompok harta fiskal, ambang kapitalisasi, dan lapisan pembukuan yang diizinkan.
 */
class GroupAset extends MasterData
{
    /** Kelompok harta berwujud menurut PMK; menentukan masa manfaat dan tarif fiskal. */
    public const TIPE_HARTA = [
        'kelompok_1',
        'kelompok_2',
        'kelompok_3',
        'kelompok_4',
        'bangunan_permanen',
        'bangunan_non_permanen',
        'bukan_objek_penyusutan',
    ];

    public const MAJOR_TYPE = ['tangible', 'intangible', 'right_of_use', 'low_value'];

    /** Lapisan pembukuan; `none` berarti buku memorandum yang tidak posting ke GL. */
    public const POSTING_LAYERS = ['current', 'operations', 'tax', 'none'];

    protected $table = 'm_group_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'tipe_harta', 'major_type', 'capitalization_threshold', 'posting_layers',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'capitalization_threshold' => 'decimal:2',
        ];
    }
}
