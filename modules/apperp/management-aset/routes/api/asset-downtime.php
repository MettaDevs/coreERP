<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Downtime\AssetDowntimeController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Downtime\MaintenanceKpiController;

// Downtime aset dan KPI pemeliharaan. Master alasan downtime terdaftar di `master-data.php`.
Route::get('downtime-aset', [AssetDowntimeController::class, 'index']);
Route::post('downtime-aset', [AssetDowntimeController::class, 'store']);
Route::patch('downtime-aset/{id}', [AssetDowntimeController::class, 'update']);
Route::delete('downtime-aset/{id}', [AssetDowntimeController::class, 'destroy']);
Route::get('kpi-pemeliharaan', MaintenanceKpiController::class);
