<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Jenis counter aset; padanan *Counter* (Asset measures) di Dynamics 365 F&O Asset Management.
 *
 * Contohnya jam operasi, kilometer, atau jumlah tindakan pada alat kesehatan. `satuan_id` menunjuk
 * satuan milik Core dan `satuan` adalah salinan kodenya untuk tampilan. Bentuk dasarnya disebutkan
 * pada `MasterData`.
 *
 * @property string $satuan_id
 * @property ?string $satuan
 */
class CounterType extends MasterData
{
    protected $table = 'aset_m_jenis_counter';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'satuan_id', 'satuan',
    ];
}
