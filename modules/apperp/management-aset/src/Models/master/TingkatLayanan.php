<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Urgensi penanganan sebuah work order; padanan "Service level" pada modul Asset
 * management Dynamics 365 F&O.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan pada
 * `MasterData`. `urutan` yang lebih kecil berarti lebih mendesak.
 *
 * @property int $urutan
 */
class TingkatLayanan extends MasterData
{
    protected $table = 'aset_m_tingkat_layanan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'urutan',
    ];

    protected function casts(): array
    {
        return [...parent::casts(), 'urutan' => 'integer'];
    }
}
