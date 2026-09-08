<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ContohA\Http\Controllers\BarangController;

/*
 * Dimuat oleh penyedia layanan module ini, bukan oleh Core.
 *
 * `konteks-module` menerima id module sebagai parameter. Itu sebabnya ia dipasang di sini,
 * pada grup rute module, dan bukan sebagai middleware global: izin bersifat per app, dan
 * middleware global tidak tahu ia sedang melayani module yang mana.
 *
 * Tidak ada token di jalur ini. Module berjalan di dalam proses Core, jadi konteksnya dibaca
 * langsung dari permintaan yang sedang dilayani.
 */

Route::middleware(['web', 'auth', 'konteks-module:contoh-a'])
    ->prefix('contoh-a')
    ->name('contoh-a.')
    ->group(function (): void {
        Route::get('barang', [BarangController::class, 'index'])->name('barang.index');
    });
