<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\Master\PaymentMethodController;

Route::get('metode-pembayaran', [PaymentMethodController::class, 'index'])
    ->name('procurement.payment-method.index');
Route::get('metode-pembayaran/{id}', [PaymentMethodController::class, 'show'])
    ->name('procurement.payment-method.show');
Route::post('metode-pembayaran', [PaymentMethodController::class, 'store'])
    ->name('procurement.payment-method.store');
Route::put('metode-pembayaran/{id}', [PaymentMethodController::class, 'update'])
    ->name('procurement.payment-method.update');
Route::patch('metode-pembayaran/{id}/status', [PaymentMethodController::class, 'updateStatus'])
    ->name('procurement.payment-method.update-status');
Route::delete('metode-pembayaran/{id}', [PaymentMethodController::class, 'destroy'])
    ->name('procurement.payment-method.destroy');
