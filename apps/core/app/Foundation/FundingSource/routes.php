<?php

use App\Foundation\FundingSource\Http\Controllers\FundingSourceController;
use App\Platform\Access\Support\CoreSecurityCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('settings/funding-sources', [FundingSourceController::class, 'page'])
        ->name('funding-sources.index')->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
    Route::prefix('api/v1/funding-sources')->name('api.funding-sources.')->group(function (): void {
        Route::get('/', [FundingSourceController::class, 'index'])->name('index')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::post('/', [FundingSourceController::class, 'store'])->name('store');
        Route::patch('{fundingSource}', [FundingSourceController::class, 'update'])->name('update');
        Route::delete('{fundingSource}', [FundingSourceController::class, 'destroy'])->name('destroy');
    });
});
