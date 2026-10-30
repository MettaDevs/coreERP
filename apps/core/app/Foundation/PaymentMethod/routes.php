<?php

use App\Foundation\PaymentMethod\Http\Controllers\PaymentMethodController;
use App\Platform\Access\Support\CoreSecurityCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('settings/payment-methods', [PaymentMethodController::class, 'page'])
        ->name('payment-methods.index')->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
    Route::prefix('api/v1/payment-methods')->name('api.payment-methods.')->group(function (): void {
        Route::get('/', [PaymentMethodController::class, 'index'])->name('index')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::get('{paymentMethod}', [PaymentMethodController::class, 'show'])->name('show')
            ->middleware(CoreSecurityCatalog::gate(CoreSecurityCatalog::REFERENCE_DATA_READ));
        Route::post('/', [PaymentMethodController::class, 'store'])->name('store');
        Route::patch('{paymentMethod}', [PaymentMethodController::class, 'update'])->name('update');
        Route::patch('{paymentMethod}/status', [PaymentMethodController::class, 'updateStatus'])->name('update-status');
        Route::delete('{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('destroy');
    });
});
