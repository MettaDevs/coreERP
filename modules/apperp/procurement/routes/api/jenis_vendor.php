<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\JenisVendorController;

Route::get('jenis-vendor', [JenisVendorController::class, 'index'])->name('procurement.jenis-vendor.index');
Route::post('jenis-vendor', [JenisVendorController::class, 'store'])->name('procurement.jenis-vendor.store');
Route::get('jenis-vendor/{id}', [JenisVendorController::class, 'show'])->name('procurement.jenis-vendor.show');
Route::put('jenis-vendor/{id}', [JenisVendorController::class, 'update'])->name('procurement.jenis-vendor.update');
Route::patch('jenis-vendor/{id}', [JenisVendorController::class, 'update']);
Route::patch('jenis-vendor/{id}/toggle-status', [JenisVendorController::class, 'toggleStatus'])->name('procurement.jenis-vendor.toggle-status');
Route::delete('jenis-vendor/{id}', [JenisVendorController::class, 'destroy'])->name('procurement.jenis-vendor.destroy');
