<?php

use App\Foundation\Item\Http\Controllers\ItemController;
use App\Platform\Access\Support\CoreSecurityCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('settings/service-items', [ItemController::class, 'page'])
        ->name('service-items.index')->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
    Route::prefix('api/v1/items')->name('api.items.')->group(function (): void {
        Route::get('/', [ItemController::class, 'index'])->name('index')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::get('{item}', [ItemController::class, 'show'])->name('show')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::post('/', [ItemController::class, 'store'])->name('store');
        Route::patch('{item}', [ItemController::class, 'update'])->name('update');
        Route::patch('{item}/status', [ItemController::class, 'updateStatus'])->name('update-status');
        Route::delete('{item}', [ItemController::class, 'destroy'])->name('destroy');
    });
});
