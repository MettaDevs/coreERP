<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Apperp\ManagementAset\Models\MasterData;

class MaintenanceJobType extends MasterData
{
    protected $table = 'aset_m_maintenance_job_type';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'category_code', 'maintenance_downtime_activities',
    ];

    protected function casts(): array
    {
        return [...parent::casts(), 'maintenance_downtime_activities' => 'boolean'];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MaintenanceJobTypeVariant::class, 'maintenance_job_type_id');
    }
}
