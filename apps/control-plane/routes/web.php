<?php

declare(strict_types=1);

use ControlPlane\Http\Controllers\Keluar;
use ControlPlane\Http\Controllers\Lingkungan\Daftar as DaftarLingkungan;
use ControlPlane\Http\Controllers\Lingkungan\Rincian as RincianLingkungan;
use ControlPlane\Http\Controllers\Lingkungan\Siapkan as SiapkanLingkungan;
use ControlPlane\Http\Controllers\Lingkungan\Simpan as SimpanLingkungan;
use ControlPlane\Http\Controllers\Masuk;
use ControlPlane\Http\Controllers\Pelanggan\Daftar as DaftarPelanggan;
use ControlPlane\Http\Controllers\Pelanggan\Simpan as SimpanPelanggan;
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
    Route::get('/pelanggan', DaftarPelanggan::class)->name('pelanggan.daftar');
    Route::post('/pelanggan', SimpanPelanggan::class)->name('pelanggan.simpan');

    Route::get('/lingkungan', DaftarLingkungan::class)->name('lingkungan.daftar');
    Route::post('/lingkungan', SimpanLingkungan::class)->name('lingkungan.simpan');
    Route::get('/lingkungan/{lingkungan}', RincianLingkungan::class)->name('lingkungan.rincian');
    Route::post('/lingkungan/{lingkungan}/siapkan', SiapkanLingkungan::class)->name('lingkungan.siapkan');
});
