<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ContohA\Http\Controllers\BarangController;
use Modules\Apperp\ContohA\Http\Controllers\HalamanBarangController;

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

        /*
         * Jalur layar, dipisah dari jalur JSON di atas.
         *
         * Nama jalurnya sama dengan id entri menu pada `app.yaml`, dan itu bukan kebetulan:
         * Core menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>`, jadi
         * mengganti salah satu tanpa yang lain membuat menunya mendarat di 404. Test
         * `HalamanModuleShellTest` membuktikan keduanya masih sejalan.
         */
        Route::get('daftar-barang', HalamanBarangController::class)->name('barang.halaman');
    });
