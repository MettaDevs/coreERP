<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\ModelAset;
use App\Support\MasterParent;

/**
 * Katalog model barang per pabrikan. Master pertama dengan dua induk yang saling
 * lepas: pabrikan wajib, jenis opsional, dan tidak ada penyaringan bertingkat di
 * antara keduanya.
 */
class ModelAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'model-aset';
    }

    protected function model(): string
    {
        return ModelAset::class;
    }

    protected function parentMasters(): array
    {
        return [
            new MasterParent(
                table: 'm_pabrikan_aset',
                column: 'pabrikan_aset_id',
                relation: 'pabrikanAset',
                label: 'pabrikan aset',
            ),
            new MasterParent(
                table: 'm_jenis_aset',
                column: 'jenis_aset_id',
                relation: 'jenisAset',
                label: 'jenis aset',
                required: false,
            ),
        ];
    }
}
