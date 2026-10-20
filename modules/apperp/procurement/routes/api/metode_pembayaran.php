<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\MetodePembayaranController;

Route::get('metode-pembayaran', [MetodePembayaranController::class, 'index'])->name('procurement.metode-pembayaran.index');
Route::post('metode-pembayaran', [MetodePembayaranController::class, 'store'])->name('procurement.metode-pembayaran.store');
Route::get('metode-pembayaran/{id}', [MetodePembayaranController::class, 'show'])->name('procurement.metode-pembayaran.show');
Route::put('metode-pembayaran/{id}', [MetodePembayaranController::class, 'update'])->name('procurement.metode-pembayaran.update');
Route::patch('metode-pembayaran/{id}', [MetodePembayaranController::class, 'update']);
Route::patch('metode-pembayaran/{id}/toggle-status', [MetodePembayaranController::class, 'toggleStatus'])->name('procurement.metode-pembayaran.toggle-status');
Route::delete('metode-pembayaran/{id}', [MetodePembayaranController::class, 'destroy'])->name('procurement.metode-pembayaran.destroy');
