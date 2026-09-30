<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MonitoringAset\AssetMonitoringController;

// Monitoring aset (pemeriksaan fisik). `selesaikan` dan `isi-otomatis` didahulukan agar `{id}`
// tidak menelannya; keduanya POST karena perintah, bukan penyuntingan field.
Route::get('monitoring-aset', [AssetMonitoringController::class, 'index']);
Route::post('monitoring-aset', [AssetMonitoringController::class, 'store']);
Route::post('monitoring-aset/{id}/selesaikan', [AssetMonitoringController::class, 'complete']);
Route::post('monitoring-aset/{id}/isi-otomatis', [AssetMonitoringController::class, 'fill']);
Route::get('monitoring-aset/{id}', [AssetMonitoringController::class, 'show']);
Route::patch('monitoring-aset/{id}', [AssetMonitoringController::class, 'update']);
Route::delete('monitoring-aset/{id}', [AssetMonitoringController::class, 'destroy']);
