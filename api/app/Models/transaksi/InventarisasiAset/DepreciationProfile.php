<?php

namespace App\Models\transaksi\InventarisasiAset;

use App\Models\MasterData;

class DepreciationProfile extends MasterData
{
    protected $table = 'm_profil_penyusutan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'method', 'frequency', 'year_basis',
        'convention', 'useful_life_periods', 'rate_percent', 'manual_schedule', 'aktif',
    ];
}
