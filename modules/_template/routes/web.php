<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\PenerbitContoh\ChangeMe\Http\Controllers\HalamanContohController;

/*
 * Dimuat oleh penyedia layanan module ini, bukan oleh Core.
 *
 * `konteks-module` menerima id module sebagai parameter. Itu sebabnya ia dipasang di sini,
 * pada grup rute module, dan bukan sebagai middleware global: izin bersifat per module, dan
 * middleware global tidak tahu ia sedang melayani module yang mana.
 *
 * Tidak ada token di jalur ini. Module berjalan di dalam proses Core, jadi konteksnya dibaca
 * langsung dari permintaan yang sedang dilayani.
 *
 * Jalur rutenya sama dengan id entri menu pada `app.yaml`, dan itu bukan kebetulan: Core
 * menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>`, jadi mengganti salah
 * satu tanpa yang lain membuat menunya mendarat di 404.
 */

Route::middleware(['web', 'auth', 'konteks-module:change-me'])
    ->prefix('change-me')
    ->name('change-me.')
    ->group(function (): void {
        Route::get('daftar-contoh', HalamanContohController::class)->name('contoh.halaman');
    });
