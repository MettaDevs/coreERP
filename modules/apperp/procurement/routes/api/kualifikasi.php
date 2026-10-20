<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\KualifikasiController;

Route::get('kualifikasi', [KualifikasiController::class, 'index'])->name('procurement.kualifikasi.index');
Route::get('kualifikasi/{id}', [KualifikasiController::class, 'show'])->name('procurement.kualifikasi.show');
