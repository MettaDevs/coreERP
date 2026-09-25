<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\DepreciationController;

Route::post('penyusutan/proposal', [DepreciationController::class, 'propose']);
Route::post('penyusutan/proposal-massal', [DepreciationController::class, 'bulk']);
Route::get('penyusutan', [DepreciationController::class, 'index']);
Route::get('penyusutan/buku', [DepreciationController::class, 'books']);
// "Post penyusutan" (TODO 11.2): pratinjau lalu proses, satu posting per entitas legal, buku,
// dan tanggal akhir periode.
Route::get('penyusutan/posting/pratinjau', [DepreciationController::class, 'postingPreview']);
Route::post('penyusutan/posting', [DepreciationController::class, 'post']);
Route::post('penyusutan/{id}/finalisasi', [DepreciationController::class, 'finalize']);
Route::post('penyusutan/{id}/reversal', [DepreciationController::class, 'reverse']);
