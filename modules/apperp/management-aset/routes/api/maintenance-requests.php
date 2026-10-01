<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MaintenanceRequest\MaintenanceRequestController;

// Permintaan pemeliharaan. Setiap perpindahan status adalah tindakan tersendiri (POST), bukan efek
// menyimpan.
Route::get('permintaan-pemeliharaan', [MaintenanceRequestController::class, 'index']);
Route::post('permintaan-pemeliharaan', [MaintenanceRequestController::class, 'store']);
Route::post('permintaan-pemeliharaan/{id}/ajukan', [MaintenanceRequestController::class, 'submit']);
Route::post('permintaan-pemeliharaan/{id}/terima', [MaintenanceRequestController::class, 'accept']);
Route::post('permintaan-pemeliharaan/{id}/tolak', [MaintenanceRequestController::class, 'reject']);
Route::post('permintaan-pemeliharaan/{id}/work-order', [MaintenanceRequestController::class, 'createWorkOrder']);
Route::get('permintaan-pemeliharaan/{id}', [MaintenanceRequestController::class, 'show']);
Route::patch('permintaan-pemeliharaan/{id}', [MaintenanceRequestController::class, 'update']);
Route::delete('permintaan-pemeliharaan/{id}', [MaintenanceRequestController::class, 'destroy']);
