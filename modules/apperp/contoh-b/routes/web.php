<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ContohB\Http\Controllers\RakController;

/*
 * Berkas ini belum dimuat siapa pun. Penyedia layanan module yang memuatnya dibuat pada
 * fase 2; sampai saat itu, isinya hanya menyatakan bentuk yang disepakati.
 */

Route::middleware(['web', 'auth'])
    ->prefix('contoh-b')
    ->name('contoh-b.')
    ->group(function (): void {
        Route::get('rak', [RakController::class, 'index'])->name('rak.index');
    });
