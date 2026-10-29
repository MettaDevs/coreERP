<?php

use App\Foundation\PlanningYear\Http\Controllers\PlanningYearController;
use App\Platform\Access\Support\CoreSecurityCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('settings/planning-years', [PlanningYearController::class, 'page'])->name('planning-years.index')
        ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
    Route::prefix('api/v1/planning-years')->name('api.planning-years.')->group(function (): void {
        Route::get('/', [PlanningYearController::class, 'index'])->name('index')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::post('/', [PlanningYearController::class, 'store'])->name('store');
        Route::patch('{planningYear}', [PlanningYearController::class, 'update'])->name('update');
        Route::delete('{planningYear}', [PlanningYearController::class, 'destroy'])->name('destroy');
    });
});
