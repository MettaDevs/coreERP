<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Akar sebab sebuah kerusakan; padanan "Fault cause" pada modul Asset management
 * Dynamics 365 F&O. Dipilih bebas saat pekerjaan ditutup dan tidak diikat ke jenis
 * aset, karena sebab seperti "aus wajar" atau "salah pemasangan" berlaku lintas aset.
 *
 * Ia dipisahkan dari tindakan perbaikan supaya pertanyaan "kenapa rusak" dan "diapakan"
 * dapat dijawab terpisah. Satu daftar gabungan tidak dapat dipecah lagi setelah datanya
 * terkumpul.
 */
class SebabKerusakan extends MasterData
{
    protected $table = 'm_sebab_kerusakan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'minta_keterangan',
    ];

    protected function casts(): array
    {
        return [...parent::casts(), 'minta_keterangan' => 'boolean'];
    }
}
