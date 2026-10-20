<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\ItemJasaController;

Route::get('item-jasa', [ItemJasaController::class, 'index'])->name('procurement.item-jasa.index');
Route::post('item-jasa', [ItemJasaController::class, 'store'])->name('procurement.item-jasa.store');
Route::get('item-jasa/{id}', [ItemJasaController::class, 'show'])->name('procurement.item-jasa.show');
Route::put('item-jasa/{id}', [ItemJasaController::class, 'update'])->name('procurement.item-jasa.update');
Route::patch('item-jasa/{id}', [ItemJasaController::class, 'update']);
Route::patch('item-jasa/{id}/toggle-status', [ItemJasaController::class, 'toggleStatus'])->name('procurement.item-jasa.toggle-status');
Route::delete('item-jasa/{id}', [ItemJasaController::class, 'destroy'])->name('procurement.item-jasa.destroy');
