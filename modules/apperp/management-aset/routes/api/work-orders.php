<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\master\ValidasiStatusWorkOrderController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PemeliharaanAset\PelaksanaanController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PemeliharaanAset\PemeliharaanAsetController;

// Bukan master: matriks aturan tetap yang hanya dapat diubah keaktifan dan keparahannya.
Route::get('validasi-status-work-order', [ValidasiStatusWorkOrderController::class, 'index']);
Route::put('validasi-status-work-order', [ValidasiStatusWorkOrderController::class, 'replace']);
Route::get('pemeliharaan-aset', [PemeliharaanAsetController::class, 'index']);
Route::get('pemeliharaan-aset/referensi/job-types', [PemeliharaanAsetController::class, 'jobTypesForAset']);
Route::post('pemeliharaan-aset', [PemeliharaanAsetController::class, 'store']);
// Rute spesifik didahulukan agar `{id}` tidak menelan `saya`.
Route::get('pemeliharaan-aset/saya', [PelaksanaanController::class, 'pekerjaanSaya']);
Route::post('pemeliharaan-aset/{id}/status', [PelaksanaanController::class, 'pindahStatus']);
Route::get('pemeliharaan-aset/{id}/jobs/{jobId}/checklist', [PelaksanaanController::class, 'checklist']);
Route::put('pemeliharaan-aset/{id}/jobs/{jobId}/checklist', [PelaksanaanController::class, 'simpanChecklist']);
Route::patch('pemeliharaan-aset/{id}/jobs/{jobId}/execution', [PelaksanaanController::class, 'simpanPelaksanaan']);
Route::post('pemeliharaan-aset/{id}/jobs/{jobId}/checklist/dari-template', [PelaksanaanController::class, 'salinDariTemplate']);
Route::get('pemeliharaan-aset/{id}', [PemeliharaanAsetController::class, 'show']);
Route::patch('pemeliharaan-aset/{id}', [PemeliharaanAsetController::class, 'update']);
Route::delete('pemeliharaan-aset/{id}', [PemeliharaanAsetController::class, 'destroy']);
