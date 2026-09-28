<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\PabrikanAset;
use Modules\Apperp\ManagementAset\Support\MasterChild;

/**
 * @extends MasterDataController<PabrikanAset>
 */
class PabrikanAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'pabrikan-aset';
    }

    protected function model(): string
    {
        return PabrikanAset::class;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'aset_m_model_aset', column: 'pabrikan_aset_id', label: 'model aset'),
            new MasterChild(table: 'aset_tr_aset', column: 'pabrikan_aset_id', label: 'aset'),
        ];
    }
}
