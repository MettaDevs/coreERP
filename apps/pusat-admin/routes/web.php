<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PusatAdmin\Http\Controllers\LingkunganController;
use PusatAdmin\Http\Controllers\LoginController;

Route::redirect('/', '/lingkungan');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'form'])->name('login');
    Route::post('/login', [LoginController::class, 'masuk']);
});

Route::post('/logout', [LoginController::class, 'keluar'])->middleware('auth');

/*
 * Setiap alamat di bawah lewat `auth` DAN `operator`.
 *
 * Keduanya, bukan salah satu. `auth` hanya menjawab "ini siapa"; yang menjawab "ia boleh di sini"
 * adalah `operator`. Pola yang justru harus dihindari ada di Core hari ini: satu rute katalog
 * terdaftar tanpa middleware apa pun sambil memulangkan nama database, sementara halaman yang
 * menampilkan data yang sama dijaga gate.
 */
Route::middleware(['auth', 'operator'])->group(function (): void {
    Route::get('/lingkungan', [LingkunganController::class, 'index'])->name('lingkungan.daftar');
    Route::post('/lingkungan', [LingkunganController::class, 'store'])->name('lingkungan.simpan');
    Route::get('/lingkungan/{lingkungan}', [LingkunganController::class, 'show'])->name('lingkungan.rincian');
});
