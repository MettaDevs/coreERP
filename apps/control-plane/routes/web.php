<?php

declare(strict_types=1);

use ControlPlane\Http\Controllers\Customers\Index as CustomerIndex;
use ControlPlane\Http\Controllers\Customers\Store as CustomerStore;
use ControlPlane\Http\Controllers\Environments\Index as EnvironmentIndex;
use ControlPlane\Http\Controllers\Environments\Provision as EnvironmentProvision;
use ControlPlane\Http\Controllers\Environments\Show as EnvironmentShow;
use ControlPlane\Http\Controllers\Environments\Store as EnvironmentStore;
use ControlPlane\Http\Controllers\Login;
use ControlPlane\Http\Controllers\Logout;
use ControlPlane\Http\Controllers\Updates\Index as UpdateIndex;
use ControlPlane\Http\Controllers\Updates\Upgrade as UpdateUpgrade;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/lingkungan');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [Login::class, 'form'])->name('login');
    Route::post('/login', [Login::class, 'submit']);
});

Route::post('/logout', Logout::class)->middleware('auth');

/*
 * Setiap alamat di bawah lewat `auth` DAN `operator`.
 *
 * Keduanya, bukan salah satu. `auth` hanya menjawab "ini siapa"; yang menjawab "ia boleh di sini"
 * adalah `operator`. Pola yang justru harus dihindari ada di Core hari ini: satu rute katalog
 * terdaftar tanpa middleware apa pun sambil memulangkan nama database, sementara halaman yang
 * menampilkan data yang sama dijaga gate.
 */
Route::middleware(['auth', 'operator'])->group(function (): void {
    Route::get('/pelanggan', CustomerIndex::class)->name('customers.index');
    Route::post('/pelanggan', CustomerStore::class)->name('customers.store');

    Route::get('/lingkungan', EnvironmentIndex::class)->name('environments.index');
    Route::post('/lingkungan', EnvironmentStore::class)->name('environments.store');
    Route::get('/lingkungan/{lingkungan}', EnvironmentShow::class)->name('environments.show');
    Route::post('/lingkungan/{lingkungan}/siapkan', EnvironmentProvision::class)->name('environments.provision');

    /*
     * Satu controller melayani kedua tombol, karena yang membedakannya hanya ada atau tidaknya satu
     * id di alamatnya. Alamatnya tetap berbahasa Indonesia seperti seluruh konsol ini — yang
     * berbahasa Inggris nama berkas dan methodnya, bukan yang dibaca operator di bilah alamat.
     */
    Route::get('/pembaruan', UpdateIndex::class)->name('updates.index');
    Route::post('/pembaruan', UpdateUpgrade::class)->name('updates.upgrade');
    Route::post('/pembaruan/{lingkungan}', UpdateUpgrade::class)->name('updates.upgrade-one');
});
