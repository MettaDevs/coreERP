<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\HumanResources\Http\Controllers\HalamanModulController;

/*
 * Layar module, dipisah dari jalur JSON di `routes/api.php`.
 *
 * Dimuat penyedia layanan module ini, bukan oleh Core.
 *
 * Bentuknya sama persis dengan `management-aset`, dan itu disengaja. Dua module yang
 * menyusun alamat layarnya dengan dua cara berbeda membuat penjaga batas harus mengenali
 * dua bentuk, dan yang kedua biasanya baru ditulis setelah ada yang lupa.
 *
 * Nama ruas `{view}` sama dengan id entri menu pada `app.yaml`, dan itu bukan kebetulan:
 * Core menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>` dari manifest
 * yang sama, jadi mengganti salah satu tanpa yang lain membuat menunya mendarat di 404.
 * `HalamanModulController` membaca daftar itu dari manifest apa adanya, sehingga tidak ada
 * daftar kedua yang bisa menyimpang.
 *
 * `{sisa?}` menampung ruas sesudah id menu — `/human-resources/workers/01JQ…/ubah` — yang
 * dulu ditulis sesudah tanda pagar oleh perutean hash milik aplikasi Vite module ini.
 * Ia harus `.*` supaya garis miring di dalamnya ikut tertangkap; bawaan Laravel berhenti
 * pada garis miring pertama.
 *
 * `konteks-module` menerima id module sebagai parameter, jadi ia dipasang di sini pada grup
 * rute module dan bukan sebagai middleware global: izin bersifat per app, dan middleware
 * global tidak tahu ia sedang melayani module yang mana.
 */

Route::middleware(['web', 'auth', 'konteks-module:human-resources'])
    ->prefix('human-resources')
    ->name('human-resources.')
    ->group(function (): void {
        Route::get('{view}/{sisa?}', HalamanModulController::class)
            ->where('sisa', '.*')
            ->name('layar');
    });
