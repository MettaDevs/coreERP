<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Disposal\AssetDisposalController;

// Draf penjualan dan pemusnahan aset sampai diposting. Draf dibuat lewat `POST penjualan-aset` dan
// `POST pemusnahan-aset` di `lifecycle-documents.php`; posting melepas aset dan menerbitkan jurnalnya.
foreach (['penjualan-aset', 'pemusnahan-aset'] as $documentType) {
    Route::get($documentType.'/{id}/pratinjau-posting', [AssetDisposalController::class, 'preview']);
    Route::post($documentType.'/{id}/posting', [AssetDisposalController::class, 'post']);
    Route::post($documentType.'/{id}/batal', [AssetDisposalController::class, 'cancel']);
    Route::get($documentType.'/{id}', [AssetDisposalController::class, 'show']);
    Route::patch($documentType.'/{id}', [AssetDisposalController::class, 'update']);
}
