<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Urgensi penanganan sebuah work order; padanan "Service level" pada modul Asset
 * management Dynamics 365 F&O.
 */
class TingkatLayanan extends MasterData
{
    protected $table = 'm_tingkat_layanan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'urutan',
    ];

    protected function casts(): array
    {
        return [...parent::casts(), 'urutan' => 'integer'];
    }
}
