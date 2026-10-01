<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MaintenanceSchedule\MaintenanceScheduleController;

// Jadwal pemeliharaan. `hitung` dan `work-order` adalah perintah, jadi POST; keduanya didahulukan
// dari `{id}`.
Route::get('jadwal-pemeliharaan', [MaintenanceScheduleController::class, 'index']);
Route::post('jadwal-pemeliharaan/hitung', [MaintenanceScheduleController::class, 'run']);
Route::post('jadwal-pemeliharaan/work-order', [MaintenanceScheduleController::class, 'createWorkOrders']);
Route::post('jadwal-pemeliharaan/{id}/abaikan', [MaintenanceScheduleController::class, 'discard']);
