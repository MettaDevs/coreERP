<?php

/*
|--------------------------------------------------------------------------
| Engine analitik
|--------------------------------------------------------------------------
|
| Dataset module, query JSON, dan eksekusi baca-saja; rancangannya di docs/todo/analitik. Setiap area
| menambah kuncinya di bagiannya sendiri, dengan variabel env berawalan `COREERP_ANALYTICS_`. Nilai
| bawaan disetel untuk on-prem satu container; menaikkannya keputusan operator, bukan bawaan.
|
*/

return [
    /*
     * Saklar sementara (area 0). Selama rantai izin analitik (KA-14) belum disetujui pemilik produk,
     * belum ada permission analitik, jadi seluruh halaman dan API analitik menjawab 404 bila saklar mati
     * dan menu Analisis data tidak tampil. Bawaan mati; `.env.example` menyalakannya untuk pengembangan
     * lokal. Dilepas area 4 begitu KA-14 disetujui.
     */
    'enabled' => (bool) env('COREERP_ANALYTICS_ENABLED', false),

    'timeouts' => [
        // `statement_timeout` query dari layar, dalam milidetik. Query yang melewatinya dihentikan
        // database dan dijawab 422 `analytics.query_timeout`.
        'interactive_ms' => (int) env('COREERP_ANALYTICS_TIMEOUT_INTERACTIVE_MS', 8000),
    ],

    'limits' => [
        // Baris hasil kelompok dari layar bila query tidak menyebut `limit`, sekaligus batas tertinggi
        // `limit`. Hasil yang lebih panjang dipotong dan ditandai `meta.truncated`.
        'rows_interactive' => (int) env('COREERP_ANALYTICS_ROWS_INTERACTIVE', 5000),
    ],
];
