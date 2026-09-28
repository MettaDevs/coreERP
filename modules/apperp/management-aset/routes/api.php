<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\HealthController;

Route::get('v1/health', HealthController::class);

// Tidak ada lagi rute `internal/v1/laporan`. Mesin laporan Core membaca definisi, layout
// bawaan, dan dataset module ini lewat `Reporting\PenyediaLaporan` di dalam proses yang
// sama; tiga rute yang dulu ada di sini beserta controller-nya dihapus pada F3-12.

Route::prefix('v1')->middleware('konteks-module:management-aset')->group(function (): void {
    // Tidak ada lagi rute `v1/context`. Izin dan konteks dikirim bersama halaman oleh
    // `HalamanModulController`, jadi layar tidak lagi menunggu satu perjalanan jaringan
    // sebelum tahu tombol mana yang boleh tampil. Dibuang pada F4-06 bersama controllernya.

    // Rute tiap fitur ada di berkasnya sendiri di `routes/api/`, supaya orang yang mengerjakan
    // fitur berbeda tidak menyunting berkas yang sama. Prefix dan middleware tetap di grup ini.
    // Berkasnya dimuat menurut nama, dan urutan itu tidak menentukan apa pun: rute spesifik yang
    // harus didahulukan dari `{id}` selalu berada di berkas yang sama dengan rute `{id}`-nya.
    foreach (glob(__DIR__.'/api/*.php') ?: [] as $routes) {
        require $routes;
    }
});
