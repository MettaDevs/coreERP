<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenancePlanDetailController;

// Baris dan objek rencana pemeliharaan, disunting di dalam form rencana. Header rencana adalah
// master biasa dan terdaftar di `master-data.php`.
Route::get('rencana-pemeliharaan/{id}/baris', [MaintenancePlanDetailController::class, 'lines']);
Route::put('rencana-pemeliharaan/{id}/baris', [MaintenancePlanDetailController::class, 'replaceLines']);
Route::get('rencana-pemeliharaan/{id}/objek', [MaintenancePlanDetailController::class, 'targets']);
Route::put('rencana-pemeliharaan/{id}/objek', [MaintenancePlanDetailController::class, 'replaceTargets']);
