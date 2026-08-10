<?php

use App\Http\Controllers\ContextController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\master\AnalisaMaintenanceController;
use App\Http\Controllers\master\BukuPenyusutanController;
use App\Http\Controllers\master\GroupAsetController;
use App\Http\Controllers\master\GroupBukuPenyusutanController;
use App\Http\Controllers\master\ItemChecklistMaintenanceController;
use App\Http\Controllers\master\JenisAsetAtributController;
use App\Http\Controllers\master\JenisAsetAtributDefinisiController;
use App\Http\Controllers\master\JenisAsetController;
use App\Http\Controllers\master\KondisiAsetController;
use App\Http\Controllers\master\LokasiAsetController;
use App\Http\Controllers\master\ModelAsetController;
use App\Http\Controllers\master\PabrikanAsetController;
use App\Http\Controllers\master\ProfilPenyusutanController;
use App\Http\Controllers\master\TipeAtributController;
use App\Http\Controllers\master\TipeAtributNilaiController;
use App\Http\Controllers\master\TipeLokasiAsetController;
use App\Http\Controllers\ReferenceDataController;
use App\Http\Controllers\transaksi\DekomisioningAset\WorkflowDecisionController;
use App\Http\Controllers\transaksi\DokumenSiklusAset\DokumenSiklusAsetController;
use App\Http\Controllers\transaksi\InventarisasiAset\AssetController;
use App\Http\Controllers\transaksi\InventarisasiAset\DepreciationController;
use App\Http\Controllers\transaksi\PerencanaanAset\PerencanaanAsetController;
use App\Http\Controllers\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetController;
use Illuminate\Support\Facades\Route;

/**
 * Master data aset. Seluruhnya datar dan saling lepas; urutan di sini hanya untuk
 * keterbacaan, bukan ketergantungan. Group dan jenis adalah dua sumbu klasifikasi
 * yang ditunjuk aset secara langsung, model aset adalah katalog per pabrikan.
 */
$masters = [
    'group-aset' => GroupAsetController::class,
    'jenis-aset' => JenisAsetController::class,
    'model-aset' => ModelAsetController::class,
    'kondisi-aset' => KondisiAsetController::class,
    'pabrikan-aset' => PabrikanAsetController::class,
    'item-checklist-maintenance' => ItemChecklistMaintenanceController::class,
    'analisa-maintenance' => AnalisaMaintenanceController::class,
    'tipe-lokasi-aset' => TipeLokasiAsetController::class,
    'lokasi-aset' => LokasiAsetController::class,
    'profil-penyusutan' => ProfilPenyusutanController::class,
    'buku-penyusutan' => BukuPenyusutanController::class,
    'tipe-atribut' => TipeAtributController::class,
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
    // Matriks group x buku disunting di dalam form group, jadi ia memakai permission
    // group dan tidak berdiri sendiri sebagai master.
    // Atribut menempel pada jenis aset dan pilihan nilainya pada tipe atribut;
    // keduanya disunting di dalam form pemiliknya, jadi memakai permission pemilik.
    Route::get('jenis-aset/{id}/atribut', [JenisAsetAtributController::class, 'index']);
    Route::put('jenis-aset/{id}/atribut', [JenisAsetAtributController::class, 'replace']);
    Route::get('jenis-aset/{id}/atribut-definisi', JenisAsetAtributDefinisiController::class);
    Route::get('tipe-atribut/{id}/nilai', [TipeAtributNilaiController::class, 'index']);
    Route::put('tipe-atribut/{id}/nilai', [TipeAtributNilaiController::class, 'replace']);
    Route::get('group-aset/{id}/buku-penyusutan', [GroupBukuPenyusutanController::class, 'index']);
    Route::put('group-aset/{id}/buku-penyusutan', [GroupBukuPenyusutanController::class, 'replace']);
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
