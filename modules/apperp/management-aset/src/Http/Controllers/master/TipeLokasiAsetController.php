<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\TipeLokasiAset;
use Modules\Apperp\ManagementAset\Support\MasterChild;

class TipeLokasiAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'tipe-lokasi-aset';
    }

    protected function model(): string
    {
        return TipeLokasiAset::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'm_lokasi_aset', column: 'tipe_lokasi_id', label: 'lokasi aset'),
        ];
    }
}
