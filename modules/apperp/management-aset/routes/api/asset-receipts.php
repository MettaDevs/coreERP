<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PenerimaanAset\PenerimaanAsetController;

// Dokumen penerimaan: satu kedatangan, banyak aset.
//
// `ringkasan` dan `aset` didahulukan dari `{id}` supaya keduanya tidak ditelan
// parameter — urutan yang sama seperti `aset/{id}/history`.
Route::get('penerimaan-aset', [PenerimaanAsetController::class, 'index']);
// Pemilih vendor milik Core untuk penerimaan (TODO 9.3.1); didahulukan dari `{id}`.
Route::get('penerimaan-aset/vendor', [PenerimaanAsetController::class, 'vendor']);
// Buku per group untuk isian saldo awal per buku (TODO 10.1.1); didahulukan dari `{id}`.
Route::get('penerimaan-aset/buku', [PenerimaanAsetController::class, 'buku']);
Route::post('penerimaan-aset', [PenerimaanAsetController::class, 'store']);
// Impor saldo awal aset lama dari CSV (TODO 10.6); didahulukan dari `{id}`.
Route::post('penerimaan-aset/impor-saldo-awal', [PenerimaanAsetController::class, 'imporSaldoAwal']);
Route::get('penerimaan-aset/impor-saldo-awal/templat', [PenerimaanAsetController::class, 'templatSaldoAwal']);
Route::post('penerimaan-aset/{id}/selesaikan', [PenerimaanAsetController::class, 'selesaikan']);
Route::get('penerimaan-aset/{id}/ringkasan', [PenerimaanAsetController::class, 'ringkasan']);
// Pratinjau jurnal perolehan sebelum diselesaikan (TODO 9.3.2).
Route::get('penerimaan-aset/{id}/pratinjau-posting', [PenerimaanAsetController::class, 'pratinjauPosting']);
Route::get('penerimaan-aset/{id}/aset', [PenerimaanAsetController::class, 'asetTerbit']);
Route::put('penerimaan-aset/{id}/aset', [PenerimaanAsetController::class, 'isiNomorSeri']);
Route::get('penerimaan-aset/{id}', [PenerimaanAsetController::class, 'show']);
Route::patch('penerimaan-aset/{id}', [PenerimaanAsetController::class, 'update']);
Route::delete('penerimaan-aset/{id}', [PenerimaanAsetController::class, 'destroy']);
