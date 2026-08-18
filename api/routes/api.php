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
use App\Http\Controllers\master\JenisAsetDetailController;
use App\Http\Controllers\master\JenisAsetModelController;
use App\Http\Controllers\master\KondisiAsetController;
use App\Http\Controllers\master\LokasiAsetController;
use App\Http\Controllers\master\MaintenanceChecklistTemplateController;
use App\Http\Controllers\master\MaintenanceChecklistVariableController;
use App\Http\Controllers\master\MaintenanceJobTypeController;
use App\Http\Controllers\master\MaintenanceJobTypeDefaultController;
use App\Http\Controllers\master\MaintenanceJobTypeVariantController;
use App\Http\Controllers\master\MaintenanceSetupLinkController;
use App\Http\Controllers\master\ModelAsetController;
use App\Http\Controllers\master\PabrikanAsetController;
use App\Http\Controllers\master\PabrikanAsetDetailController;
use App\Http\Controllers\master\ProfilPenyusutanController;
use App\Http\Controllers\master\SebabKerusakanController;
use App\Http\Controllers\master\TindakanPerbaikanController;
use App\Http\Controllers\master\TingkatLayananController;
use App\Http\Controllers\master\TipeAtributController;
use App\Http\Controllers\master\TipeAtributNilaiController;
use App\Http\Controllers\master\TipeLokasiAsetController;
use App\Http\Controllers\master\TipeWorkOrderController;
use App\Http\Controllers\master\TradeController;
use App\Http\Controllers\master\ValidasiStatusWorkOrderController;
use App\Http\Controllers\ReferenceDataController;
use App\Http\Controllers\TenantProvisioningController;
use App\Http\Controllers\transaksi\DekomisioningAset\WorkflowDecisionController;
use App\Http\Controllers\transaksi\DokumenSiklusAset\DokumenSiklusAsetController;
use App\Http\Controllers\transaksi\InventarisasiAset\AssetController;
use App\Http\Controllers\transaksi\InventarisasiAset\DepreciationController;
use App\Http\Controllers\transaksi\PemeliharaanAset\PelaksanaanController;
use App\Http\Controllers\transaksi\PemeliharaanAset\PemeliharaanAsetController;
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
    'maintenance-job-types' => MaintenanceJobTypeController::class,
    'maintenance-job-type-variants' => MaintenanceJobTypeVariantController::class,
    'maintenance-job-type-defaults' => MaintenanceJobTypeDefaultController::class,
    'maintenance-checklist-variables' => MaintenanceChecklistVariableController::class,
    'maintenance-checklist-templates' => MaintenanceChecklistTemplateController::class,
    'tipe-work-order' => TipeWorkOrderController::class,
    'trade' => TradeController::class,
    'sebab-kerusakan' => SebabKerusakanController::class,
    'tindakan-perbaikan' => TindakanPerbaikanController::class,
    'tingkat-layanan' => TingkatLayananController::class,
    'tipe-lokasi-aset' => TipeLokasiAsetController::class,
    'lokasi-aset' => LokasiAsetController::class,
    'profil-penyusutan' => ProfilPenyusutanController::class,
    'buku-penyusutan' => BukuPenyusutanController::class,
    'tipe-atribut' => TipeAtributController::class,
];

Route::get('v1/health', HealthController::class);
Route::post('internal/v1/workflow-events', [WorkflowDecisionController::class, 'store'])->middleware('coreerp-event');
Route::post('internal/v1/provisioning/tenant', [TenantProvisioningController::class, 'store'])->middleware('coreerp-event');

Route::prefix('v1')->middleware('coreerp')->group(function () use ($masters): void {
    Route::get('context', ContextController::class);
    Route::get('reference-data/units-of-measure', [ReferenceDataController::class, 'unitsOfMeasure']);
    Route::get('reference-data/kelompok-harta-fiskal', [ReferenceDataController::class, 'fiscalClassifications']);

    Route::get('aset', [AssetController::class, 'index']);
    Route::post('aset', [AssetController::class, 'store']);
    // Rute spesifik didahulukan agar `{id}` tidak menelan `history` dan `penempatan`.
    Route::get('aset/{id}/history', [AssetController::class, 'history']);
    Route::post('aset/{id}/penempatan', [AssetController::class, 'place']);
    Route::get('aset/{id}', [AssetController::class, 'show']);
    Route::patch('aset/{id}', [AssetController::class, 'update']);
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
    // Bukan master: matriks aturan tetap yang hanya dapat diubah keaktifan dan keparahannya.
    Route::get('validasi-status-work-order', [ValidasiStatusWorkOrderController::class, 'index']);
    Route::put('validasi-status-work-order', [ValidasiStatusWorkOrderController::class, 'replace']);
    Route::get('pemeliharaan-aset', [PemeliharaanAsetController::class, 'index']);
    Route::get('pemeliharaan-aset/referensi/job-types', [PemeliharaanAsetController::class, 'jobTypesForAsset']);
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
    foreach (['dekomisioning-aset', 'penjualan-aset', 'pemusnahan-aset'] as $documentType) {
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
    // Angka turunan dibaca terpisah, bukan menempel pada record jenis aset: hanya record
    // yang sedang dibuka yang membutuhkannya, dan tiap angka menegakkan izin resource-nya
    // sendiri.
    Route::get('jenis-aset/{id}/detail', JenisAsetDetailController::class);
    Route::get('jenis-aset/{id}/maintenance-job-types', [MaintenanceSetupLinkController::class, 'jenisAsetAssetTypes']);
    Route::put('jenis-aset/{id}/maintenance-job-types', [MaintenanceSetupLinkController::class, 'replaceJenisAsetAssetTypes']);
    Route::get('maintenance-job-types/{id}/variants', [MaintenanceSetupLinkController::class, 'jobTypeVariants']);
    Route::get('maintenance-job-types/{id}/asset-types', [MaintenanceSetupLinkController::class, 'jobTypeAssetTypes']);
    Route::put('maintenance-job-types/{id}/asset-types', [MaintenanceSetupLinkController::class, 'replaceJobTypeAssetTypes']);
    Route::get('maintenance-checklist-variables/{id}/values', [MaintenanceSetupLinkController::class, 'variableValues']);
    Route::put('maintenance-checklist-variables/{id}/values', [MaintenanceSetupLinkController::class, 'replaceVariableValues']);
    Route::get('maintenance-checklist-templates/{id}/lines', [MaintenanceSetupLinkController::class, 'templateLines']);
    Route::put('maintenance-checklist-templates/{id}/lines', [MaintenanceSetupLinkController::class, 'replaceTemplateLines']);
    Route::post('maintenance-job-type-defaults/{id}/copy', [MaintenanceJobTypeDefaultController::class, 'copy']);
    Route::get('pabrikan-aset/{id}/detail', PabrikanAsetDetailController::class);
    Route::put('jenis-aset/{id}/models', [JenisAsetModelController::class, 'replace']);
    Route::get('tipe-atribut/{id}/nilai', [TipeAtributNilaiController::class, 'index']);
    Route::put('tipe-atribut/{id}/nilai', [TipeAtributNilaiController::class, 'replace']);
    Route::get('group-aset/{id}/buku-penyusutan', [GroupBukuPenyusutanController::class, 'index']);
    Route::put('group-aset/{id}/buku-penyusutan', [GroupBukuPenyusutanController::class, 'replace']);
    Route::post('penyusutan/proposal', [DepreciationController::class, 'propose']);
    Route::post('penyusutan/proposal-massal', [DepreciationController::class, 'bulk']);
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
