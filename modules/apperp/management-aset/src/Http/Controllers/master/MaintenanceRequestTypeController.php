<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceRequestType;
use Modules\Apperp\ManagementAset\Support\MasterParent;

/**
 * Jenis permintaan pemeliharaan; padanan *Maintenance request type* F&O.
 *
 * Tipe work order opsional: bila diisi, work order yang dibuat dari permintaan jenis ini memakainya
 * sebagai bawaan.
 *
 * @extends MasterDataController<MaintenanceRequestType>
 */
class MaintenanceRequestTypeController extends MasterDataController
{
    protected function resource(): string
    {
        return 'jenis-permintaan-pemeliharaan';
    }

    protected function model(): string
    {
        return MaintenanceRequestType::class;
    }

    protected function parentMasters(): array
    {
        return [
            new MasterParent('aset_m_tipe_work_order', 'tipe_work_order_id', 'tipeWorkOrder', 'Tipe work order', required: false),
        ];
    }
}
