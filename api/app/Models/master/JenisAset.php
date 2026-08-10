<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Sumbu klasifikasi teknis aset; padanan "Asset type" pada modul Asset management
 * Dynamics 365 F&O. Datar dan berdiri sendiri: yang menggantung padanya adalah
 * perlakuan maintenance dan atribut, bukan tingkat klasifikasi di atasnya.
 */
class JenisAset extends MasterData
{
    protected $table = 'm_jenis_aset';
}
