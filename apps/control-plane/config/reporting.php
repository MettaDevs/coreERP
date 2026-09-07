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
    | Alamat API app
    |--------------------------------------------------------------------------
    |
    | Core memanggil app untuk meminta dataset laporan. Alamatnya diturunkan dari nama
    | service API pada release yang terpasang (`http://<api_service>`), yang pada Compose
    | selalu dapat di-resolve dari container Core. Deployment yang service-nya tidak
    | satu network dengan Core dapat menimpanya lewat JSON `{"<app_id>": "<url>"}`.
    |
    */
    'app_api_endpoints' => json_decode((string) env('COREERP_APP_API_ENDPOINTS', '{}'), true) ?: [],
    'app_timeout' => (int) env('COREERP_APP_REPORT_TIMEOUT', 60),

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
    // Dataset melewati HTTP dari app ke Core; batas ini menjaga satu ekspor tidak
    // menguasai worker dan memori.
    'max_rows' => (int) env('COREERP_REPORTING_MAX_ROWS', 50000),
    'max_layout_kb' => (int) env('COREERP_REPORTING_MAX_LAYOUT_KB', 5120),
    // Ekspor yang masih menunggu atau berjalan per pengguna. Satu orang yang menekan
    // Cetak berkali-kali tidak boleh membuat pengguna lain menunggu.
    'max_active_per_user' => (int) env('COREERP_REPORTING_MAX_ACTIVE_PER_USER', 5),
    'history_per_user' => 50,
];
