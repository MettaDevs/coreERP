<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ContohA\Http\Controllers\BarangController;

/*
 * Berkas ini belum dimuat siapa pun. Penyedia layanan module yang memuatnya dibuat pada
 * fase 2; sampai saat itu, isinya hanya menyatakan bentuk yang disepakati.
 */

Route::middleware(['web', 'auth'])
    ->prefix('contoh-a')
    ->name('contoh-a.')
    ->group(function (): void {
        Route::get('barang', [BarangController::class, 'index'])->name('barang.index');
    });
