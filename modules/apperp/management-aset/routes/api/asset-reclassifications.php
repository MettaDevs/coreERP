<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Reclassification\AssetReclassificationController;

// Reklasifikasi aset: pindah group aset dan pecah aset. Pratinjau dan posting didahulukan agar `{id}` tidak
// menelannya; posting adalah POST karena perintah, bukan penyuntingan.
Route::get('reklasifikasi-aset', [AssetReclassificationController::class, 'index']);
Route::post('reklasifikasi-aset', [AssetReclassificationController::class, 'store']);
Route::get('reklasifikasi-aset/{id}/pratinjau-posting', [AssetReclassificationController::class, 'preview']);
Route::post('reklasifikasi-aset/{id}/posting', [AssetReclassificationController::class, 'post']);
Route::get('reklasifikasi-aset/{id}', [AssetReclassificationController::class, 'show']);
Route::patch('reklasifikasi-aset/{id}', [AssetReclassificationController::class, 'update']);
Route::delete('reklasifikasi-aset/{id}', [AssetReclassificationController::class, 'destroy']);
