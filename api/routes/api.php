<?php

use App\Http\Controllers\ContextController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\master\AnalisaMaintenanceController;
use App\Http\Controllers\master\EntitasAsetController;
use App\Http\Controllers\master\GroupAsetController;
use App\Http\Controllers\master\ItemChecklistMaintenanceController;
use App\Http\Controllers\master\JenisAsetController;
use App\Http\Controllers\master\KategoriAsetController;
use App\Http\Controllers\master\KondisiAsetController;
use App\Http\Controllers\master\PabrikanAsetController;
use App\Http\Controllers\ReferenceDataController;
use App\Http\Controllers\transaksi\DekomisioningAset\WorkflowDecisionController;
use App\Http\Controllers\transaksi\DokumenSiklusAset\DokumenSiklusAsetController;
use App\Http\Controllers\transaksi\InventarisasiAset\AssetController;
use App\Http\Controllers\transaksi\InventarisasiAset\AssetLocationController;
use App\Http\Controllers\transaksi\InventarisasiAset\DepreciationController;
use App\Http\Controllers\transaksi\InventarisasiAset\DepreciationProfileController;
use App\Http\Controllers\transaksi\PerencanaanAset\PerencanaanAsetController;
use App\Http\Controllers\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetController;
use Illuminate\Support\Facades\Route;

/** Master data aset; urutan mengikuti rantai klasifikasi lalu master mandiri. */
$masters = [
    'entitas-aset' => EntitasAsetController::class,
    'group-aset' => GroupAsetController::class,
    'kategori-aset' => KategoriAsetController::class,
    'jenis-aset' => JenisAsetController::class,
    'kondisi-aset' => KondisiAsetController::class,
    'pabrikan-aset' => PabrikanAsetController::class,
    'item-checklist-maintenance' => ItemChecklistMaintenanceController::class,
    'analisa-maintenance' => AnalisaMaintenanceController::class,
];

Route::get('v1/health', HealthController::class);
Route::post('internal/v1/workflow-events', [WorkflowDecisionController::class, 'store'])->middleware('coreerp-event');

Route::prefix('v1')->middleware('coreerp')->group(function () use ($masters): void {
    Route::get('context', ContextController::class);
    Route::get('reference-data/units-of-measure', [ReferenceDataController::class, 'unitsOfMeasure']);

    Route::get('aset', [AssetController::class, 'index']);
    Route::post('aset', [AssetController::class, 'store']);
    Route::post('aset/{id}/penempatan', [AssetController::class, 'place']);
    Route::get('aset/{id}/history', [AssetController::class, 'history']);
    Route::get('perencanaan-aset', [PerencanaanAsetController::class, 'index']);
    Route::post('perencanaan-aset', [PerencanaanAsetController::class, 'store']);
    Route::get('perencanaan-aset/{id}', [PerencanaanAsetController::class, 'show']);
    Route::patch('perencanaan-aset/{id}', [PerencanaanAsetController::class, 'update']);
    Route::delete('perencanaan-aset/{id}', [PerencanaanAsetController::class, 'destroy']);
    Route::get('permintaan-pembelian-aset', [PermintaanPengadaanAsetController::class, 'index']);
    Route::post('permintaan-pembelian-aset', [PermintaanPengadaanAsetController::class, 'store']);
    Route::get('permintaan-pembelian-aset/{id}', [PermintaanPengadaanAsetController::class, 'show']);
    Route::patch('permintaan-pembelian-aset/{id}', [PermintaanPengadaanAsetController::class, 'update']);
    Route::post('permintaan-pembelian-aset/{id}/batal', [PermintaanPengadaanAsetController::class, 'cancel']);
    foreach (['pemeliharaan-aset', 'dekomisioning-aset', 'penjualan-aset', 'pemusnahan-aset'] as $documentType) {
        Route::get($documentType, [DokumenSiklusAsetController::class, 'indexByRoute']);
        Route::post($documentType, [DokumenSiklusAsetController::class, 'storeByRoute']);
    }
    Route::apiResource('lokasi-aset', AssetLocationController::class)->except(['create', 'edit']);
    Route::get('profil-penyusutan', [DepreciationProfileController::class, 'index']);
    Route::post('profil-penyusutan', [DepreciationProfileController::class, 'store']);
    Route::post('penyusutan/proposal', [DepreciationController::class, 'propose']);
    Route::get('penyusutan', [DepreciationController::class, 'index']);
    Route::get('penyusutan/buku', [DepreciationController::class, 'books']);
    Route::post('penyusutan/{id}/finalisasi', [DepreciationController::class, 'finalize']);
    Route::post('penyusutan/{id}/reversal', [DepreciationController::class, 'reverse']);

    foreach ($masters as $slug => $controller) {
        Route::get($slug, [$controller, 'index']);
        Route::post($slug, [$controller, 'store']);
        Route::get($slug.'/{id}', [$controller, 'show']);
        Route::patch($slug.'/{id}', [$controller, 'update']);
        Route::delete($slug.'/{id}', [$controller, 'destroy']);
    }
});
