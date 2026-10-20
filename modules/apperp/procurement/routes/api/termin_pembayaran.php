<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\TerminPembayaranController;

Route::get('termin-pembayaran', [TerminPembayaranController::class, 'index'])->name('procurement.termin-pembayaran.index');
Route::post('termin-pembayaran', [TerminPembayaranController::class, 'store'])->name('procurement.termin-pembayaran.store');
Route::get('termin-pembayaran/{id}', [TerminPembayaranController::class, 'show'])->name('procurement.termin-pembayaran.show');
Route::put('termin-pembayaran/{id}', [TerminPembayaranController::class, 'update'])->name('procurement.termin-pembayaran.update');
Route::patch('termin-pembayaran/{id}', [TerminPembayaranController::class, 'update']);
Route::patch('termin-pembayaran/{id}/toggle-status', [TerminPembayaranController::class, 'toggleStatus'])->name('procurement.termin-pembayaran.toggle-status');
Route::delete('termin-pembayaran/{id}', [TerminPembayaranController::class, 'destroy'])->name('procurement.termin-pembayaran.destroy');
