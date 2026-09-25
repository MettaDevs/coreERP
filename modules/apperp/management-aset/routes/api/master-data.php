<?php

use Illuminate\Support\Facades\Route;
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
Route::get('jenis-aset/{id}/maintenance-job-types', [MaintenanceSetupLinkController::class, 'jenisAsetAsetTypes']);
Route::put('jenis-aset/{id}/maintenance-job-types', [MaintenanceSetupLinkController::class, 'replaceJenisAsetAsetTypes']);
Route::get('maintenance-job-types/{id}/variants', [MaintenanceSetupLinkController::class, 'jobTypeVariants']);
Route::get('maintenance-job-types/{id}/jenis-aset', [MaintenanceSetupLinkController::class, 'jobTypeAsetTypes']);
Route::put('maintenance-job-types/{id}/jenis-aset', [MaintenanceSetupLinkController::class, 'replaceJobTypeAsetTypes']);
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

foreach ($masters as $slug => $controller) {
    Route::get($slug, [$controller, 'index']);
    Route::post($slug, [$controller, 'store']);
    Route::get($slug.'/{id}', [$controller, 'show']);
    Route::patch($slug.'/{id}', [$controller, 'update']);
    Route::delete($slug.'/{id}', [$controller, 'destroy']);
}
