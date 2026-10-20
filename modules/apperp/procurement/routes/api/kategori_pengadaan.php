<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Apperp\Procurement\Http\Controllers\master\KategoriPengadaanController;

Route::get('kategori-pengadaan', [KategoriPengadaanController::class, 'index'])->name('procurement.kategori-pengadaan.index');
Route::post('kategori-pengadaan', [KategoriPengadaanController::class, 'store'])->name('procurement.kategori-pengadaan.store');
Route::get('kategori-pengadaan/{id}', [KategoriPengadaanController::class, 'show'])->name('procurement.kategori-pengadaan.show');
Route::put('kategori-pengadaan/{id}', [KategoriPengadaanController::class, 'update'])->name('procurement.kategori-pengadaan.update');
Route::patch('kategori-pengadaan/{id}', [KategoriPengadaanController::class, 'update']);
Route::patch('kategori-pengadaan/{id}/toggle-status', [KategoriPengadaanController::class, 'toggleStatus'])->name('procurement.kategori-pengadaan.toggle-status');
Route::delete('kategori-pengadaan/{id}', [KategoriPengadaanController::class, 'destroy'])->name('procurement.kategori-pengadaan.destroy');
