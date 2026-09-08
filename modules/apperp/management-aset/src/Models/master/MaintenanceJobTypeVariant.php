<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

class MaintenanceJobTypeVariant extends MasterData
{
    protected $table = 'aset_m_maintenance_job_type_variant';

    protected $fillable = ['tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'maintenance_job_type_id'];

    public function maintenanceJobType(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJobType::class, 'maintenance_job_type_id');
    }
}
