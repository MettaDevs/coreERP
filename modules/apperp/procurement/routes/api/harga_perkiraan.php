<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\HargaPerkiraanController;

Route::get('harga-perkiraan', [HargaPerkiraanController::class, 'index'])->name('procurement.harga-perkiraan.index');
Route::post('harga-perkiraan', [HargaPerkiraanController::class, 'store'])->name('procurement.harga-perkiraan.store');
Route::get('harga-perkiraan/{id}', [HargaPerkiraanController::class, 'show'])->name('procurement.harga-perkiraan.show');
Route::put('harga-perkiraan/{id}', [HargaPerkiraanController::class, 'update'])->name('procurement.harga-perkiraan.update');
Route::delete('harga-perkiraan/{id}', [HargaPerkiraanController::class, 'destroy'])->name('procurement.harga-perkiraan.destroy');
