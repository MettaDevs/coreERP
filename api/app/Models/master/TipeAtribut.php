<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Padanan "Attribute type" di Dynamics 365 F&O.
 *
 * Inilah cara menambah pembeda aset tanpa menambah tingkat klasifikasi baru: tenant
 * membuat atributnya sendiri, menempelkannya ke jenis aset, dan aset mewarisinya.
 */
class TipeAtribut extends MasterData
{
    public const DATA_TYPES = ['text', 'number', 'boolean', 'date', 'fixed_list', 'value_range'];

    /** Tipe yang nilainya dipilih dari daftar tetap milik atribut itu sendiri. */
    public const LIST_TYPE = 'fixed_list';

    /** Tipe yang nilainya angka dan dibatasi min/max. */
    public const RANGE_TYPE = 'value_range';

    protected $table = 'm_tipe_atribut';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'data_type', 'satuan', 'min_value', 'max_value',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
        ];
    }
}
