<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\Master\OwnerEstimateController;

Route::get('harga-perkiraan', [OwnerEstimateController::class, 'index'])
    ->name('procurement.owner-estimate.index');
Route::get('harga-perkiraan/{id}', [OwnerEstimateController::class, 'show'])
    ->name('procurement.owner-estimate.show');
Route::post('harga-perkiraan', [OwnerEstimateController::class, 'store'])
    ->name('procurement.owner-estimate.store');
Route::put('harga-perkiraan/{id}', [OwnerEstimateController::class, 'update'])
    ->name('procurement.owner-estimate.update');
Route::delete('harga-perkiraan/{id}', [OwnerEstimateController::class, 'destroy'])
    ->name('procurement.owner-estimate.destroy');

