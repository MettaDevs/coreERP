<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Rencana pemeliharaan preventif; padanan *Maintenance plan* di Dynamics 365 F&O Asset Management.
 *
 * Baris rencana menyatakan kapan pekerjaan jatuh tempo, objek rencana menyatakan aset mana yang
 * dikenainya. Bentuk dasarnya disebutkan pada `MasterData`.
 *
 * @property Carbon $tanggal_mulai
 * @property int $toleransi_hari_sebelum
 * @property int $toleransi_hari_sesudah
 */
class MaintenancePlan extends MasterData
{
    protected $table = 'aset_m_rencana_pemeliharaan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'tanggal_mulai', 'toleransi_hari_sebelum', 'toleransi_hari_sesudah',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'tanggal_mulai' => 'date',
            'toleransi_hari_sebelum' => 'integer',
            'toleransi_hari_sesudah' => 'integer',
        ];
    }

    /** @return HasMany<MaintenancePlanLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(MaintenancePlanLine::class, 'rencana_pemeliharaan_id');
    }

    /** @return HasMany<MaintenancePlanTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(MaintenancePlanTarget::class, 'rencana_pemeliharaan_id');
    }
}
