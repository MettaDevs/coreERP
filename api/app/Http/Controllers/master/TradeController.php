<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\Trade;

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
