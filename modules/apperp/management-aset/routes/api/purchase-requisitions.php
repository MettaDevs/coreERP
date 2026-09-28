<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetController;

Route::get('permintaan-pembelian-aset', [PermintaanPengadaanAsetController::class, 'index']);
Route::post('permintaan-pembelian-aset', [PermintaanPengadaanAsetController::class, 'store']);
Route::get('permintaan-pembelian-aset/{id}', [PermintaanPengadaanAsetController::class, 'show']);
Route::patch('permintaan-pembelian-aset/{id}', [PermintaanPengadaanAsetController::class, 'update']);
Route::post('permintaan-pembelian-aset/{id}/batal', [PermintaanPengadaanAsetController::class, 'cancel']);
