<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\Master\VendorPrequalificationController;

Route::get('prakualifikasi/number-policy', [VendorPrequalificationController::class, 'numberPolicy'])
    ->name('procurement.prequalification.number-policy');
Route::get('prakualifikasi/vendors/lookup', [VendorPrequalificationController::class, 'lookupVendors'])
    ->name('procurement.prequalification.vendors.lookup');

Route::get('prakualifikasi', [VendorPrequalificationController::class, 'index'])
    ->name('procurement.prequalification.index');
Route::post('prakualifikasi', [VendorPrequalificationController::class, 'store'])
    ->name('procurement.prequalification.store');

Route::get('prakualifikasi/{id}', [VendorPrequalificationController::class, 'show'])
    ->name('procurement.prequalification.show');
Route::put('prakualifikasi/{id}', [VendorPrequalificationController::class, 'update'])
    ->name('procurement.prequalification.update');
Route::patch('prakualifikasi/{id}', [VendorPrequalificationController::class, 'update'])
    ->name('procurement.prequalification.patch');
Route::delete('prakualifikasi/{id}', [VendorPrequalificationController::class, 'destroy'])
    ->name('procurement.prequalification.destroy');
