<?php

use Illuminate\Support\Facades\Route;
use Modules\Apperp\ManagementAset\Http\Controllers\HealthController;
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
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\DokumenSiklusAset\DokumenSiklusAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\AsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset\DepreciationController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MutasiAset\MutasiAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PenerimaanAset\PenerimaanAsetController;
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

// Tidak ada lagi rute `internal/v1/laporan`. Mesin laporan Core membaca definisi, layout
// bawaan, dan dataset module ini lewat `Reporting\PenyediaLaporan` di dalam proses yang
// sama; tiga rute yang dulu ada di sini beserta controller-nya dihapus pada F3-12.

Route::prefix('v1')->middleware('konteks-module:management-aset')->group(function () use ($masters): void {
    // Tidak ada lagi rute `v1/context`. Izin dan konteks dikirim bersama halaman oleh
    // `HalamanModulController`, jadi layar tidak lagi menunggu satu perjalanan jaringan
    // sebelum tahu tombol mana yang boleh tampil. Dibuang pada F4-06 bersama controllernya.
    Route::get('reference-data/units-of-measure', [ReferenceDataController::class, 'unitsOfMeasure']);
    Route::get('reference-data/kelompok-harta-fiskal', [ReferenceDataController::class, 'fiscalClassifications']);
    // Unit kerja dan orang milik Core, supaya layar menampilkan nama dan bukan ULID.
    Route::get('reference-data/unit-kerja', [ReferenceDataController::class, 'operatingUnits']);
    Route::get('reference-data/anggota', [ReferenceDataController::class, 'members']);

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
    // Mutasi aset. `selesaikan` didahulukan agar `{id}` tidak menelannya, dan ia POST
    // bukan PATCH karena menyelesaikan serah terima menciptakan penempatan baru untuk
    // setiap aset pada dokumen — perintah, bukan penyuntingan field.
    Route::get('mutasi-aset', [MutasiAsetController::class, 'index']);
    Route::post('mutasi-aset', [MutasiAsetController::class, 'store']);
    Route::post('mutasi-aset/{id}/selesaikan', [MutasiAsetController::class, 'selesaikan']);
    Route::get('mutasi-aset/{id}', [MutasiAsetController::class, 'show']);
    Route::patch('mutasi-aset/{id}', [MutasiAsetController::class, 'update']);
    Route::delete('mutasi-aset/{id}', [MutasiAsetController::class, 'destroy']);

    // Dokumen penerimaan: satu kedatangan, banyak aset.
    //
    // `ringkasan` dan `aset` didahulukan dari `{id}` supaya keduanya tidak ditelan
    // parameter — urutan yang sama seperti `aset/{id}/history`.
    Route::get('penerimaan-aset', [PenerimaanAsetController::class, 'index']);
    Route::post('penerimaan-aset', [PenerimaanAsetController::class, 'store']);
    Route::post('penerimaan-aset/{id}/selesaikan', [PenerimaanAsetController::class, 'selesaikan']);
    Route::get('penerimaan-aset/{id}/ringkasan', [PenerimaanAsetController::class, 'ringkasan']);
    Route::get('penerimaan-aset/{id}/aset', [PenerimaanAsetController::class, 'asetTerbit']);
    Route::put('penerimaan-aset/{id}/aset', [PenerimaanAsetController::class, 'isiNomorSeri']);
    Route::get('penerimaan-aset/{id}', [PenerimaanAsetController::class, 'show']);
    Route::patch('penerimaan-aset/{id}', [PenerimaanAsetController::class, 'update']);
    Route::delete('penerimaan-aset/{id}', [PenerimaanAsetController::class, 'destroy']);
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
