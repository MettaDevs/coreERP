<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\Master\VendorTypeController;

Route::get('jenis-vendor', [VendorTypeController::class, 'index'])
    ->name('procurement.vendor-type.index');
Route::get('jenis-vendor/{id}', [VendorTypeController::class, 'show'])
    ->name('procurement.vendor-type.show');
Route::post('jenis-vendor', [VendorTypeController::class, 'store'])
    ->name('procurement.vendor-type.store');
Route::put('jenis-vendor/{id}', [VendorTypeController::class, 'update'])
    ->name('procurement.vendor-type.update');
Route::patch('jenis-vendor/{id}/status', [VendorTypeController::class, 'updateStatus'])
    ->name('procurement.vendor-type.update-status');
Route::delete('jenis-vendor/{id}', [VendorTypeController::class, 'destroy'])
    ->name('procurement.vendor-type.destroy');
