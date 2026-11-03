<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\AsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\BudgetedAssetController;

Route::get('aset', [AsetController::class, 'index']);
Route::post('aset', [BudgetedAssetController::class, 'store']);
Route::patch('aset/{id}/planning', [BudgetedAssetController::class, 'update']);
// POST aset hanya membuat aset rencana tanpa perolehan, buku, atau posting aktual.
// Aset aktual tetap hanya lahir dari penyelesaian dokumen penerimaan.
// `POST aset/{id}/penempatan` dibuang 17 September 2026. Ia satu-satunya yang pernah
// menulis `lifecycle_state = 'in_use'` — nilai yang tidak pernah dibaca logika mana pun
// — dan seluruh pekerjaannya kini dikerjakan dokumen mutasi, yang membawa nomor,
// berita acara, dan riwayat penempatan yang menyebut buktinya.
Route::get('aset/{id}/history', [AsetController::class, 'history']);
// Riwayat perubahan per field dari log perubahan Core, sesudah hak dan cakupan atas aset ini diperiksa.
Route::get('aset/{id}/riwayat-perubahan', [AsetController::class, 'changeHistory']);
// Pratinjau jurnal koreksi nilai perolehan sebelum disimpan (TODO 12, K-36).
Route::get('aset/{id}/pratinjau-koreksi', [AsetController::class, 'adjustmentPreview']);
Route::get('aset/{id}', [AsetController::class, 'show']);
Route::patch('aset/{id}', [AsetController::class, 'update']);
