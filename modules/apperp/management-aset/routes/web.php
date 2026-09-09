<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\HalamanModulController;

/*
 * Layar module, dipisah dari jalur JSON di `routes/api.php`.
 *
 * Dimuat penyedia layanan module ini, bukan oleh Core.
 *
 * Nama jalurnya sama dengan id entri menu pada `app.yaml`, dan itu bukan kebetulan: Core
 * menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>` dari manifest yang
 * sama, jadi mengganti salah satu tanpa yang lain membuat menunya mendarat di 404.
 * `HalamanModulController` membaca daftar itu dari manifest apa adanya, sehingga tidak ada
 * daftar kedua yang bisa menyimpang.
 *
 * `{sisa?}` menampung ruas sesudah id menu — `/pemeliharaan-aset/01JQ…/ubah` — yang dulu
 * ditulis sesudah tanda pagar. Ia harus `.*` supaya garis miring di dalamnya ikut tertangkap;
 * bawaan Laravel berhenti pada garis miring pertama.
 *
 * `konteks-module` menerima id module sebagai parameter, jadi ia dipasang di sini pada grup
 * rute module dan bukan sebagai middleware global: izin bersifat per app, dan middleware
 * global tidak tahu ia sedang melayani module yang mana.
 */

Route::middleware(['web', 'auth', 'konteks-module:management-aset'])
    ->prefix('management-aset')
    ->name('management-aset.')
    ->group(function (): void {
        Route::get('{view}/{sisa?}', HalamanModulController::class)
            ->where('sisa', '.*')
            ->name('layar');
    });
