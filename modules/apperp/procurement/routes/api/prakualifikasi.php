<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\PrakualifikasiController;

Route::get('prakualifikasi/vendors/lookup', [PrakualifikasiController::class, 'lookupVendors'])->name('procurement.prakualifikasi.lookup');

Route::get('prakualifikasi', [PrakualifikasiController::class, 'index'])->name('procurement.prakualifikasi.index');
Route::post('prakualifikasi', [PrakualifikasiController::class, 'store'])->name('procurement.prakualifikasi.store');

Route::get('prakualifikasi/{id}', [PrakualifikasiController::class, 'show'])->name('procurement.prakualifikasi.show');
Route::put('prakualifikasi/{id}', [PrakualifikasiController::class, 'update'])->name('procurement.prakualifikasi.update');
Route::delete('prakualifikasi/{id}', [PrakualifikasiController::class, 'destroy'])->name('procurement.prakualifikasi.destroy');
