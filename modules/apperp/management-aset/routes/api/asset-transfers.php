<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MutasiAset\MutasiAsetController;

// Mutasi aset. `selesaikan` didahulukan agar `{id}` tidak menelannya, dan ia POST
// bukan PATCH karena menyelesaikan serah terima menciptakan penempatan baru untuk
// setiap aset pada dokumen — perintah, bukan penyuntingan field.
Route::get('mutasi-aset', [MutasiAsetController::class, 'index']);
Route::post('mutasi-aset', [MutasiAsetController::class, 'store']);
Route::post('mutasi-aset/{id}/selesaikan', [MutasiAsetController::class, 'selesaikan']);
Route::get('mutasi-aset/{id}', [MutasiAsetController::class, 'show']);
Route::patch('mutasi-aset/{id}', [MutasiAsetController::class, 'update']);
Route::delete('mutasi-aset/{id}', [MutasiAsetController::class, 'destroy']);
