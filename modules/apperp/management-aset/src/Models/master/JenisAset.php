<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Sumbu klasifikasi teknis aset; padanan "Asset type" pada modul Asset management
 * Dynamics 365 F&O. Datar dan berdiri sendiri: yang menggantung padanya adalah
 * perlakuan maintenance dan atribut, bukan tingkat klasifikasi di atasnya.
 */
class JenisAset extends MasterData
{
    protected $table = 'aset_m_jenis_aset';
}
