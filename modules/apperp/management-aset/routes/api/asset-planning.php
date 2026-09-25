<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PerencanaanAset\PerencanaanAsetController;

Route::get('perencanaan-aset', [PerencanaanAsetController::class, 'index']);
Route::post('perencanaan-aset', [PerencanaanAsetController::class, 'store']);
Route::get('perencanaan-aset/{id}', [PerencanaanAsetController::class, 'show']);
Route::patch('perencanaan-aset/{id}', [PerencanaanAsetController::class, 'update']);
Route::delete('perencanaan-aset/{id}', [PerencanaanAsetController::class, 'destroy']);
