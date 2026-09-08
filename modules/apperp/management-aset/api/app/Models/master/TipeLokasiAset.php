<?php

namespace App\Models\master;

use App\Models\MasterData;

/**
 * Padanan "Functional location type" di Dynamics 365 F&O. Datar: kedalaman pohon
 * lokasi ditentukan isi data lewat `m_lokasi_aset.parent_id`, bukan lewat tipe.
 * Contoh isi tenant: Site, Gedung, Lantai, Ruangan, Area penyimpanan.
 */
class TipeLokasiAset extends MasterData
{
    protected $table = 'm_tipe_lokasi_aset';
}
