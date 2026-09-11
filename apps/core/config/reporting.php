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
    'max_layout_kb' => (int) env('COREERP_REPORTING_MAX_LAYOUT_KB', 5120),
    // Ekspor yang masih menunggu atau berjalan per pengguna. Satu orang yang menekan
    // Cetak berkali-kali tidak boleh membuat pengguna lain menunggu.
    'max_active_per_user' => (int) env('COREERP_REPORTING_MAX_ACTIVE_PER_USER', 5),
    'history_per_user' => 50,
];
