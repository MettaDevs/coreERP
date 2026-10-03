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
    'timeouts' => [
        // `statement_timeout` query dari layar, dalam milidetik. Query yang melewatinya dihentikan
        // database dan dijawab 422 `analytics.query_timeout`.
        'interactive_ms' => (int) env('COREERP_ANALYTICS_TIMEOUT_INTERACTIVE_MS', 8000),
    ],

    'limits' => [
        // Baris hasil kelompok dari layar bila query tidak menyebut `limit`, sekaligus batas tertinggi
        // `limit`. Hasil yang lebih panjang dipotong dan ditandai `meta.truncated`.
        'rows_interactive' => (int) env('COREERP_ANALYTICS_ROWS_INTERACTIVE', 5000),

        // Batas bentuk query (area 2), diperiksa `QueryValidator` sebelum ada SQL: kolom pengelompokan,
        // nilai yang dihitung, kolom saringan, dan kunci urutan per query. Terlalu banyak dijawab 422
        // `analytics.limit_exceeded`.
        'dimensions' => (int) env('COREERP_ANALYTICS_MAX_DIMENSIONS', 4),
        'measures' => (int) env('COREERP_ANALYTICS_MAX_MEASURES', 12),
        'filters' => (int) env('COREERP_ANALYTICS_MAX_FILTERS', 20),
        'sort' => (int) env('COREERP_ANALYTICS_MAX_SORT', 3),
    ],
];
