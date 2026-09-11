<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Tipe work order; padanan "Work order type" pada modul Asset management Dynamics 365
 * F&O. Ia membawa aturan, bukan sekadar label: apa yang wajib diisi sebelum pekerjaan
 * boleh dinyatakan selesai ditentukan di sini, sebagai data tenant, bukan sebagai
 * percabangan di controller.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan pada
 * `MasterData`.
 *
 * @property bool $satu_pekerja
 */
class TipeWorkOrder extends MasterData
{
    protected $table = 'aset_m_tipe_work_order';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'satu_pekerja',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'satu_pekerja' => 'boolean',
        ];
    }
}
