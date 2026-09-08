<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\ContextController;
use Modules\Apperp\ManagementAset\Http\Controllers\HealthController;
use Modules\Apperp\ManagementAset\Http\Controllers\laporan\LaporanInternalController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\AnalisaMaintenanceController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\BukuPenyusutanController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\GroupAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\GroupBukuPenyusutanController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\ItemChecklistMaintenanceController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\JenisAsetAtributController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\JenisAsetAtributDefinisiController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\JenisAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\JenisAsetDetailController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\JenisAsetModelController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\KondisiAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\LokasiAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceChecklistTemplateController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceChecklistVariableController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceJobTypeController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceJobTypeDefaultController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceJobTypeVariantController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceSetupLinkController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\ModelAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\PabrikanAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\PabrikanAsetDetailController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\ProfilPenyusutanController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\SebabKerusakanController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TindakanPerbaikanController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TingkatLayananController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TipeAtributController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TipeAtributNilaiController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TipeLokasiAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TipeWorkOrderController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TradeController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\ValidasiStatusWorkOrderController;
use Modules\Apperp\ManagementAset\Http\Controllers\ReferenceDataController;
use Modules\Apperp\ManagementAset\Http\Controllers\TenantProvisioningController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\DekomisioningAset\WorkflowDecisionController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\DokumenSiklusAset\DokumenSiklusAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\AssetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\DepreciationController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PemeliharaanAset\PelaksanaanController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PemeliharaanAset\PemeliharaanAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PerencanaanAset\PerencanaanAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetController;

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
// Dua rute berikut adalah panggilan balik Core ke module lewat HTTP, dan keduanya berhenti
// masuk akal begitu keduanya berada di proses yang sama: keputusan workflow menjadi event
// Laravel biasa pada F3-09, dan penyediaan data awal tenant menjadi event in-process pada
// F3-11. Aliasnya sengaja dibiarkan menunjuk `coreerp-event` yang sudah tidak terdaftar,
// supaya ia gagal berisik kalau ada yang memuat rute ini sebelum kedua task itu selesai —
// bukan diam-diam melayani permintaan tanpa pemeriksaan apa pun.
Route::post('internal/v1/workflow-events', [WorkflowDecisionController::class, 'store'])->middleware('coreerp-event');
Route::post('internal/v1/provisioning/tenant', [TenantProvisioningController::class, 'store'])->middleware('coreerp-event');

// Laporan: dipanggil Core dengan token konteks pengguna yang meminta cetak, sehingga
// permission dan scope organisasi ditegakkan seperti request biasa. Layout, antrean
// ekspor, dan render ada di Core; app hanya menyerahkan definisi, layout bawaan, dan
// dataset. Lihat docs/dev/23-document-rendering.md di repository CoreERP.
Route::prefix('internal/v1/laporan')->middleware('konteks-module:management-aset')->group(function (): void {
    Route::get('{kode}', [LaporanInternalController::class, 'show']);
    Route::get('{kode}/layouts/{key}', [LaporanInternalController::class, 'builtinLayout']);
    Route::post('{kode}/dataset', [LaporanInternalController::class, 'dataset']);
});

Route::prefix('v1')->middleware('konteks-module:management-aset')->group(function () use ($masters): void {
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
