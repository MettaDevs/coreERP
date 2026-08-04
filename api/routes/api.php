<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\HumanResourcesController;
use Illuminate\Support\Facades\Route;

Route::get('v1/health', HealthController::class);
Route::prefix('v1')->middleware('coreerp')->group(function (): void {
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
