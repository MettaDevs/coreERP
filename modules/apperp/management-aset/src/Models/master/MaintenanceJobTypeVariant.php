<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Varian sebuah jenis pekerjaan maintenance; padanan "Maintenance job type variant" di
 * Dynamics 365 F&O.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan
 * pada `MasterData`.
 *
 * @property string $maintenance_job_type_id
 */
class MaintenanceJobTypeVariant extends MasterData
{
    protected $table = 'aset_m_maintenance_job_type_variant';

    protected $fillable = ['tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif', 'maintenance_job_type_id'];

    /** @return BelongsTo<MaintenanceJobType, $this> */
    public function maintenanceJobType(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJobType::class, 'maintenance_job_type_id');
    }
}
