<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Jenis permintaan pemeliharaan; padanan *Maintenance request type* F&O.
 *
 * Tipe work order di sini menjadi bawaan work order yang dibuat dari permintaan jenis ini. Bentuk
 * dasarnya disebutkan pada `MasterData`.
 *
 * @property ?string $tipe_work_order_id
 */
class MaintenanceRequestType extends MasterData
{
    protected $table = 'aset_m_jenis_permintaan_pemeliharaan';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'tipe_work_order_id',
    ];

    /** @return BelongsTo<TipeWorkOrder, $this> */
    public function tipeWorkOrder(): BelongsTo
    {
        return $this->belongsTo(TipeWorkOrder::class, 'tipe_work_order_id');
    }
}
