<?php

use App\Foundation\PaymentTerm\Http\Controllers\PaymentTermController;
use App\Platform\Access\Support\CoreSecurityCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('settings/payment-terms', [PaymentTermController::class, 'page'])
        ->name('payment-terms.index')->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
    Route::prefix('api/v1/payment-terms')->name('api.payment-terms.')->group(function (): void {
        Route::get('/', [PaymentTermController::class, 'index'])->name('index')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::get('{paymentTerm}', [PaymentTermController::class, 'show'])->name('show')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::post('/', [PaymentTermController::class, 'store'])->name('store');
        Route::patch('{paymentTerm}', [PaymentTermController::class, 'update'])->name('update');
        Route::patch('{paymentTerm}/status', [PaymentTermController::class, 'updateStatus'])->name('update-status');
        Route::delete('{paymentTerm}', [PaymentTermController::class, 'destroy'])->name('destroy');
    });
});
