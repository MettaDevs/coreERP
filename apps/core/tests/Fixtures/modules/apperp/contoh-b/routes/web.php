<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ContohB\Http\Controllers\RakController;

/*
 * Dimuat oleh penyedia layanan module ini, bukan oleh Core.
 *
 * `konteks-module` menerima id module sebagai parameter. Itu sebabnya ia dipasang di sini,
 * pada grup rute module, dan bukan sebagai middleware global: izin bersifat per app, dan
 * middleware global tidak tahu ia sedang melayani module yang mana.
 */

Route::middleware(['web', 'auth', 'konteks-module:contoh-b'])
    ->prefix('contoh-b')
    ->name('contoh-b.')
    ->group(function (): void {
        Route::get('rak', [RakController::class, 'index'])->name('rak.index');
    });
