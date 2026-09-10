<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;

/**
 * @extends MasterDataController<KondisiAset>
 */
class KondisiAsetController extends MasterDataController
{
    protected function resource(): string
    {
        return 'kondisi-aset';
    }

    protected function model(): string
    {
        return KondisiAset::class;
    }
}
