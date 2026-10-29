<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\Master\ProcurementCategoryController;

Route::get('kategori-pengadaan', [ProcurementCategoryController::class, 'index'])
    ->name('procurement.procurement-category.index');
Route::get('kategori-pengadaan/{id}', [ProcurementCategoryController::class, 'show'])
    ->name('procurement.procurement-category.show');
Route::post('kategori-pengadaan', [ProcurementCategoryController::class, 'store'])
    ->name('procurement.procurement-category.store');
Route::put('kategori-pengadaan/{id}', [ProcurementCategoryController::class, 'update'])
    ->name('procurement.procurement-category.update');
Route::patch('kategori-pengadaan/{id}/status', [ProcurementCategoryController::class, 'updateStatus'])
    ->name('procurement.procurement-category.update-status');
Route::delete('kategori-pengadaan/{id}', [ProcurementCategoryController::class, 'destroy'])
    ->name('procurement.procurement-category.destroy');
