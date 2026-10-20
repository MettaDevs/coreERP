<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\ItemSubkontController;

Route::get('item-subkont', [ItemSubkontController::class, 'index'])->name('procurement.item-subkont.index');
Route::post('item-subkont', [ItemSubkontController::class, 'store'])->name('procurement.item-subkont.store');
Route::get('item-subkont/{id}', [ItemSubkontController::class, 'show'])->name('procurement.item-subkont.show');
Route::put('item-subkont/{id}', [ItemSubkontController::class, 'update'])->name('procurement.item-subkont.update');
Route::patch('item-subkont/{id}', [ItemSubkontController::class, 'update']);
Route::patch('item-subkont/{id}/toggle-status', [ItemSubkontController::class, 'toggleStatus'])->name('procurement.item-subkont.toggle-status');
Route::delete('item-subkont/{id}', [ItemSubkontController::class, 'destroy'])->name('procurement.item-subkont.destroy');
