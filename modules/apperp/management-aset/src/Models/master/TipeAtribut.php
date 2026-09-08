<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Padanan "Attribute type" di Dynamics 365 F&O.
 *
 * Inilah cara menambah pembeda aset tanpa menambah tingkat klasifikasi baru: tenant
 * membuat atributnya sendiri, menempelkannya ke jenis aset, dan aset mewarisinya.
 */
class TipeAtribut extends MasterData
{
    public const DATA_TYPES = ['string', 'decimal', 'integer', 'date', 'boolean'];

    /**
     * Tipe yang satuannya bermakna. Teks, ya/tidak, tanggal, dan daftar tetap tidak pernah
     * membawa satuan, jadi satuan yang terkirim untuk tipe-tipe itu diabaikan, bukan
     * disimpan diam-diam untuk kemudian tampil di tempat yang tidak diharapkan.
     */
    public const NUMERIC_TYPES = ['decimal', 'integer'];

    protected $table = 'aset_m_tipe_atribut';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'data_type', 'data_type_locked', 'satuan_id', 'satuan', 'min_value', 'max_value',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'data_type_locked' => 'boolean',
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
        ];
    }
}
