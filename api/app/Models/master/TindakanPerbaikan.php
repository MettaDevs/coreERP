<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Perbaikan yang dikerjakan atas sebuah kerusakan; padanan "Fault remedy" pada modul
 * Asset management Dynamics 365 F&O. Seperti sebab kerusakan, ia dipilih bebas saat
 * pekerjaan ditutup dan tidak diikat ke jenis aset.
 */
class TindakanPerbaikan extends MasterData
{
    protected $table = 'm_tindakan_perbaikan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'minta_keterangan',
    ];

    protected function casts(): array
    {
        return [...parent::casts(), 'minta_keterangan' => 'boolean'];
    }
}
