<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\Master\VendorQualificationController;

Route::get('kualifikasi', [VendorQualificationController::class, 'index'])
    ->name('procurement.kualifikasi.index');
Route::get('kualifikasi/{id}', [VendorQualificationController::class, 'show'])
    ->name('procurement.kualifikasi.show');
