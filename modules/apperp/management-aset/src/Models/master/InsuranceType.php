<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Jenis asuransi; padanan *Insurance Type* Business Central, misalnya kebakaran atau kendaraan
 * bermotor. Hanya golongan polis: tidak ada aturan yang bergantung padanya. Bentuk dasarnya
 * disebutkan pada `MasterData`.
 */
class InsuranceType extends MasterData
{
    protected $table = 'aset_m_jenis_asuransi';
}
