<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\AsetController;

Route::get('aset', [AsetController::class, 'index']);
// Rute spesifik didahulukan agar `{id}` tidak menelan `history`.
//
// `POST aset` dibuang 18 September 2026. Aset kini hanya lahir dari dokumen
// penerimaan: satu berkas untuk satu kedatangan, dengan jumlah per baris, rujukan
// permintaan pembelian, dan peringatan ambang kapitalisasi sebelum nomornya terbit.
// Selama dua pintu masih terbuka, "inventarisasi aset adalah penerimaan" tidak benar
// — dan izin `management-aset.aset.create` sekarang berarti menyelesaikan penerimaan.
//
// `POST aset/{id}/penempatan` dibuang 17 September 2026. Ia satu-satunya yang pernah
// menulis `lifecycle_state = 'in_use'` — nilai yang tidak pernah dibaca logika mana pun
// — dan seluruh pekerjaannya kini dikerjakan dokumen mutasi, yang membawa nomor,
// berita acara, dan riwayat penempatan yang menyebut buktinya.
Route::get('aset/{id}/history', [AsetController::class, 'history']);
Route::get('aset/{id}', [AsetController::class, 'show']);
Route::patch('aset/{id}', [AsetController::class, 'update']);
