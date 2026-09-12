<?php

declare(strict_types=1);

use ControlPlane\Http\Controllers\Keluar;
use ControlPlane\Http\Controllers\Lingkungan\Daftar;
use ControlPlane\Http\Controllers\Lingkungan\Rincian;
use ControlPlane\Http\Controllers\Lingkungan\Simpan;
use ControlPlane\Http\Controllers\Masuk;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/lingkungan');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [Masuk::class, 'form'])->name('login');
    Route::post('/login', [Masuk::class, 'kirim']);
});

Route::post('/logout', Keluar::class)->middleware('auth');

/*
 * Setiap alamat di bawah lewat `auth` DAN `operator`.
 *
 * Keduanya, bukan salah satu. `auth` hanya menjawab "ini siapa"; yang menjawab "ia boleh di sini"
 * adalah `operator`. Pola yang justru harus dihindari ada di Core hari ini: satu rute katalog
 * terdaftar tanpa middleware apa pun sambil memulangkan nama database, sementara halaman yang
 * menampilkan data yang sama dijaga gate.
 */
Route::middleware(['auth', 'operator'])->group(function (): void {
    Route::get('/lingkungan', Daftar::class)->name('lingkungan.daftar');
    Route::post('/lingkungan', Simpan::class)->name('lingkungan.simpan');
    Route::get('/lingkungan/{lingkungan}', Rincian::class)->name('lingkungan.rincian');
});
