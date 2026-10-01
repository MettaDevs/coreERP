<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\master\AssetTypeCounterController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\CounterReading\CounterReadingController;

// Counter aset: relasi jenis aset ke jenis counter (disunting dari form jenis aset, memakai
// permission jenis aset) dan pembacaan counter. Master jenis counter terdaftar di `master-data.php`.
Route::get('jenis-aset/{id}/counter', [AssetTypeCounterController::class, 'index']);
Route::put('jenis-aset/{id}/counter', [AssetTypeCounterController::class, 'replace']);
Route::get('pembacaan-counter', [CounterReadingController::class, 'index']);
Route::post('pembacaan-counter', [CounterReadingController::class, 'store']);
// Didahulukan dari `{id}` walau metodenya berbeda, supaya urutannya tetap terbaca.
Route::get('pembacaan-counter/counter-aset', [CounterReadingController::class, 'assetCounters']);
Route::delete('pembacaan-counter/{id}', [CounterReadingController::class, 'destroy']);
