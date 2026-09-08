<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\Trade;

class TradeController extends MasterDataController
{
    protected function resource(): string
    {
        return 'trade';
    }

    protected function model(): string
    {
        return Trade::class;
    }
}
