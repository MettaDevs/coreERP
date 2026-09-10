<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\HumanResources\Http\Controllers\HealthController;
use Modules\Apperp\HumanResources\Http\Controllers\HumanResourcesController;

Route::get('v1/health', HealthController::class);

// Alias `coreerp` yang dipakai berkas ini sebelumnya didaftarkan `bootstrap/app.php` milik app
// lama, dan berkas itu ikut terbuang bersama kerangkanya. Alias yang tidak terdaftar tidak
// gagal saat rutenya ditulis; ia gagal saat rutenya dipanggil, sebagai 500 di tangan pengguna.
// Penggantinya `konteks-module`, yang menerima id module sebagai parameter karena izin bersifat
// per app dan middleware tidak punya cara lain untuk tahu ia sedang melayani module yang mana.
Route::prefix('v1')->middleware('konteks-module:human-resources')->group(function (): void {
    Route::get('operating-units', [HumanResourcesController::class, 'operatingUnits']);
    Route::get('core-members', [HumanResourcesController::class, 'coreMembers']);
    Route::get('workers', [HumanResourcesController::class, 'workers']);
    Route::post('workers', [HumanResourcesController::class, 'storeWorker']);
    Route::get('jobs', [HumanResourcesController::class, 'jobs']);
    Route::post('jobs', [HumanResourcesController::class, 'storeJob']);
    Route::get('positions', [HumanResourcesController::class, 'positions']);
    Route::post('positions', [HumanResourcesController::class, 'storePosition']);
    Route::get('worker-position-assignments', [HumanResourcesController::class, 'assignments']);
    Route::post('worker-position-assignments', [HumanResourcesController::class, 'storeAssignment']);
});
