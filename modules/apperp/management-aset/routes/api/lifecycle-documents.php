<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\DokumenSiklusAset\DokumenSiklusAsetController;

// Dokumen siklus aset: dekomisioning, penjualan, dan pemusnahan memakai satu controller.
foreach (['dekomisioning-aset', 'penjualan-aset', 'pemusnahan-aset'] as $documentType) {
    Route::get($documentType, [DokumenSiklusAsetController::class, 'indexByRoute']);
    Route::post($documentType, [DokumenSiklusAsetController::class, 'storeByRoute']);
}
