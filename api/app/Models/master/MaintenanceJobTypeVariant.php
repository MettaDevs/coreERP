<?php

namespace App\Models\master;

use App\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceJobTypeVariant extends MasterData
{
    protected $table = 'm_maintenance_job_type_variant';

    protected $fillable = ['tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'maintenance_job_type_id'];

    public function maintenanceJobType(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJobType::class, 'maintenance_job_type_id');
    }
}
