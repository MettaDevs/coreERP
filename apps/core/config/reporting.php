<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Engine render dokumen
    |--------------------------------------------------------------------------
    |
    | Layout Word dan Excel diisi di Core. Perubahan ke PDF diserahkan ke `core-renderer`
    | (Gotenberg + LibreOffice): stateless, tidak mengenal tenant, dipakai bersama semua
    | app pada satu deployment. Lihat docs/dev/23-document-rendering.md.
    |
    */
    'renderer_url' => env('COREERP_RENDERER_URL'),
    'renderer_timeout' => (int) env('COREERP_RENDERER_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan berkas
    |--------------------------------------------------------------------------
    |
    | Layout unggahan tenant, salinan layout bawaan app, dan hasil ekspor. Worker Core
    | menulis hasil dan API Core menyajikannya, jadi keduanya harus melihat disk yang
    | sama: volume bersama pada Compose, object storage pada cloud multi-instance.
    |
    */
    'disk' => env('COREERP_REPORTING_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Batas
    |--------------------------------------------------------------------------
    */
    // Hasil ekspor dihapus setelah lewat masa ini; dokumen selalu dapat dibuat ulang.
    'retention_days' => (int) env('COREERP_REPORTING_RETENTION_DAYS', 7),
    // Dataset dibaca dari module di proses yang sama; batas ini menjaga satu ekspor tidak
    // menguasai worker dan memori.
    'max_rows' => (int) env('COREERP_REPORTING_MAX_ROWS', 50000),
    // Ekspor daftar di layar (K-27) tidak menyusun data di memori: barisnya dibaca bertahap dan ditulis
    // langsung ke berkas. Sampai batas satu lembar Excel (1.048.575 baris) hasilnya xlsx; lebih dari itu
    // CSV, sampai batas ini. Batas waktu job (10 menit) tetap berlaku.
    'list_export_max_csv_rows' => (int) env('COREERP_REPORTING_LIST_EXPORT_MAX_CSV_ROWS', 2000000),
    // Baris xlsx paling banyak; tidak dapat melebihi batas satu lembar Excel. Lebih kecil hanya berguna untuk
    // menguji peralihan ke CSV.
    'list_export_max_xlsx_rows' => (int) env('COREERP_REPORTING_LIST_EXPORT_MAX_XLSX_ROWS', 1048575),
    'max_layout_kb' => (int) env('COREERP_REPORTING_MAX_LAYOUT_KB', 5120),
    // Ekspor yang masih menunggu atau berjalan per pengguna. Satu orang yang menekan
    // Cetak berkali-kali tidak boleh membuat pengguna lain menunggu.
    'max_active_per_user' => (int) env('COREERP_REPORTING_MAX_ACTIVE_PER_USER', 5),
    'history_per_user' => 50,
    // Percobaan untuk gangguan sesaat — layanan PDF terlambat, menolak sambungan, menjawab 5xx, atau
    // worker yang mati di tengah ekspor — beserta jedanya dalam detik. Kegagalan tetap (layout,
    // data terlalu besar, hak) tidak pernah diulang. Lihat RunReportExport.
    'export_attempts' => (int) env('COREERP_REPORTING_EXPORT_ATTEMPTS', 3),
    'export_retry_seconds' => [15, 60],

    /*
    |--------------------------------------------------------------------------
    | Format nilai bertipe
    |--------------------------------------------------------------------------
    |
    | Placeholder yang menyatakan `'type' => 'money'` pada `fields()` definisi laporan
    | diformat Core dengan mata uang ini. Fase ini hanya IDR (K-19). Presisinya tidak ditulis
    | di sini: ia dibaca dari setelan mata uang tenant, sama dengan pembulatan jurnal.
    |
    */

    'currency' => 'IDR',
    'currency_symbols' => ['IDR' => 'Rp'],
];
