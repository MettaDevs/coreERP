<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\TipeLokasiAset;
use App\Support\MasterChild;

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
