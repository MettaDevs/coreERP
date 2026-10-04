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

        // Widget per dasbor (area 6). Widget yang melewatinya ditolak 422 `analytics.limit_exceeded` saat
        // ditambahkan; dasbor penuh membuka terlalu banyak query sekaligus.
        'widgets_per_dashboard' => (int) env('COREERP_ANALYTICS_WIDGETS_PER_DASHBOARD', 24),

        // Area 9: query analitik yang boleh dihitung bersamaan per tenant. Query berikutnya dijawab 429
        // `analytics.busy` dengan `Retry-After`, supaya analitik tidak menahan semua proses PHP server
        // on-prem dan layar transaksi tetap terlayani. Hasil dari cache tidak memakai jatah ini.
        'concurrent_per_tenant' => (int) env('COREERP_ANALYTICS_CONCURRENT_PER_TENANT', 4),
    ],

    // Area 9: cache hasil di tabel `analytics_query_cache` database tenant, bukan cache store Laravel
    // (KA-18). `default_ttl_seconds` dipakai widget yang tidak menyebut TTL sendiri; TTL di bawah 60 detik
    // dinaikkan ke 60, dan 0 berarti tanpa cache. Hasil yang sesudah dikompres lebih besar dari
    // `max_payload_kb` tidak disimpan.
    'cache' => [
        'default_ttl_seconds' => (int) env('COREERP_ANALYTICS_CACHE_TTL_SECONDS', 300),
        'max_payload_kb' => (int) env('COREERP_ANALYTICS_CACHE_MAX_PAYLOAD_KB', 512),
    ],

    // Area 9: permintaan analisis dari layar per pengguna per menit (limiter `analytics-interactive`),
    // termasuk tombol Muat ulang yang melewati cache.
    'rate_limits' => [
        'interactive_per_minute' => (int) env('COREERP_ANALYTICS_RATE_LIMIT_PER_MINUTE', 120),
    ],

    // Area 9: masa simpan bawaan catatan query (`analytics_query_log`, PQ-05). Admin tenant dapat
    // mengubahnya di Pengaturan → Retensi data, tidak kurang dari 7 hari.
    'log' => [
        'retention_days' => (int) env('COREERP_ANALYTICS_LOG_RETENTION_DAYS', 90),
    ],

    // Area 15: publikasi yang dibaca sistem luar lewat klien integrasi. `rows_max` adalah batas baris hasil
    // satu publikasi sebelum dibagi per halaman; hasil yang lebih panjang dipotong dan ditandai
    // `meta.truncated`. `timeout_ms` adalah `statement_timeout` query publikasi, lebih longgar daripada layar
    // karena pemanggilnya mesin yang menunggu, bukan orang. Rate limit-nya milik klien integrasi
    // (`coreerp.integration_api_rate_limit`), bersama endpoint integrasi lain.
    'publications' => [
        'rows_max' => (int) env('COREERP_ANALYTICS_PUBLICATION_ROWS_MAX', 20000),
        'timeout_ms' => (int) env('COREERP_ANALYTICS_PUBLICATION_TIMEOUT_MS', 15000),
    ],
];
