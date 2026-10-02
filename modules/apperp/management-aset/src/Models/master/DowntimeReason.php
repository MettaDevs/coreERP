<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Alasan downtime; padanan *Maintenance downtime reason code* Dynamics 365 F&O.
 *
 * `masuk_kpi` padanan *KPI include*: downtime beralasan ini ikut mengurangi availability dan
 * menambah jumlah henti. Henti terencana biasanya dimatikan, karena tidak menggambarkan aset yang
 * bermasalah. Bentuk dasarnya disebutkan pada `MasterData`.
 *
 * @property bool $masuk_kpi
 */
class DowntimeReason extends MasterData
{
    protected $table = 'aset_m_alasan_downtime';

    protected $fillable = ['tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'masuk_kpi'];

    protected function casts(): array
    {
        return [...parent::casts(), 'masuk_kpi' => 'boolean'];
    }
}
