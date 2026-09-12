<?php

return [
    // Canonical official apps. Entitlements and module installations reference these IDs.
    'database' => 'core_erp',
    /*
     * Nama koneksi untuk tabel sisi pusat — identitas, pelanggan, daftar tenant, registry
     * environment, akses operator. Kosong berarti "ikut koneksi bawaan", dan itulah bawaannya.
     *
     * On-prem kosong selamanya: di sana tidak ada sisi pusat yang terpisah, dan Core memang harus
     * sanggup menjadi keseluruhannya. Yang membacanya trait App\Support\Pusat\MilikPusat.
     */
    'control_connection' => env('COREERP_CONTROL_CONNECTION'),

    /*
     * Domain dasar yang di bawahnya tiap lingkungan memperoleh alamatnya sendiri.
     *
     *   production : <tenant>.contoh.co.id
     *   selain itu : <tenant>--<lingkungan>.<jenis>.contoh.co.id
     *
     * **Kosong berarti satu alamat untuk semua, dan itu bawaannya.** On-prem melayani satu
     * pelanggan dari satu alamat selamanya, dan lingkungan pengembangan sebelum DNS disiapkan juga
     * begitu. Selama ia kosong, `TetapkanLingkungan` tidak pernah menyala — bukan gagal, tidak
     * menyala — dan seluruh perilaku hari ini utuh.
     *
     * Untuk mencobanya di mesin sendiri, isi `localhost`: peramban modern menyelesaikan setiap
     * `*.localhost` ke mesin sendiri, jadi `pelanggan--uji.demo.localhost:8000` bekerja tanpa
     * menyentuh DNS sama sekali.
     *
     * Di server, bentuk ini menuntut satu sertifikat berisi empat nama — `*.contoh.co.id`,
     * `*.demo.contoh.co.id`, `*.sandbox.contoh.co.id`, dan `contoh.co.id` sendiri, karena wildcard
     * tidak mencakup domain induknya. Alasan lengkapnya di
     * `docs/todo/environment-dan-pusat-admin/README.md`.
     */
    'domain_dasar' => env('COREERP_DOMAIN_DASAR'),

    /*
     * Label yang tidak pernah menjadi lingkungan, meski berada di bawah domain yang sama.
     *
     * Konsol operator dan alamat pemasaran berbentuk satu label — persis bentuk alamat produksi.
     * Tanpa daftar ini, `admin.contoh.co.id` akan dicari sebagai tenant bernama "admin", tidak
     * ditemukan, lalu dijawab 404: konsol operator mati dengan pesan yang tidak menyebut sebabnya
     * sama sekali.
     *
     * Menambah baris di sini berarti menyatakan label itu memang bukan milik pelanggan. Itu
     * keputusan produk, bukan keputusan pembangunan — pelanggan yang slug-nya kebetulan `api` akan
     * kehilangan alamatnya tanpa pernah tahu kenapa.
     */
    'label_bukan_lingkungan' => ['admin', 'www', 'api'],

    // `deployment` dibuang pada 11 September 2026 bersama satu-satunya pembacanya: pendaftaran
    // usaha kini menulis baris `environments`, bukan `tenant_deployments`. Sebelumnya `pull_images`
    // dan `release_root` dibuang dengan alasan yang sama. Tempat kerja sebuah tenant sekarang fakta
    // di database, bukan setelan proses — lihat `docs/todo/environment-dan-pusat-admin`.
    'provider' => [
        'email' => env('COREERP_PROVIDER_EMAIL', 'provider@coreerp.local'),
        'password' => env('COREERP_PROVIDER_PASSWORD'),
    ],
    'app_context_signing_key' => env('COREERP_APP_CONTEXT_SIGNING_KEY'),

    /*
     * Token bersama yang dipegang pusat admin ketika ia memerintah Core.
     *
     * Arah pusat → Core tidak dapat memakai kredensial app: penjaganya menuntut app yang terpasang
     * pada sebuah tenant, sedangkan pusat admin tidak terpasang di mana pun dan justru bekerja pada
     * tenant yang belum ada. Lihat App\Http\Middleware\HanyaPusatAdmin.
     *
     * Kosong berarti pemasangan ini **tidak menerima perintah pusat admin sama sekali** — bukan
     * menerima semuanya. Itu bawaan yang benar untuk on-prem dan lingkungan lokal, yang memang
     * tidak punya pusat admin.
     */
    'control_plane_token' => env('COREERP_CONTROL_PLANE_TOKEN'),
    // Penerima event Core yang berjalan sebagai proses tersendiri. Tiap baris:
    // `{"type": "...", "url": "...", "module": "..."}`. Kunci `module` opsional dan berisi id
    // module; bila module dengan id itu dimuat runtime ini, `workflow-events:publish` berhenti
    // mengirim HTTP ke sana — module tersebut sudah menerima eventnya langsung, di dalam
    // transaksi keputusannya. Baris tanpa `module` selalu dianggap di luar proses.
    'event_endpoints' => json_decode((string) env('COREERP_EVENT_ENDPOINTS', '[]'), true) ?: [],

    // Requests per minute per app+tenant on the internal number sequence API. Sized for normal document traffic,
    // not for a caller trying to burn a tenant's number range.
    'internal_api_rate_limit' => env('COREERP_INTERNAL_API_RATE_LIMIT', 600),
    'registration_rate_limit' => env('COREERP_REGISTRATION_RATE_LIMIT', 5),
    'password_breach_check' => env('COREERP_PASSWORD_BREACH_CHECK', true),

    // Reserved-but-unfinished reservations allowed per continuous sequence. An app that reserves and never confirms
    // is broken; stopping it early keeps the pool usable and makes the fault obvious.
    'max_outstanding_reservations' => env('COREERP_MAX_OUTSTANDING_RESERVATIONS', 500),

    // Retention for rows that only exist to prove a number was handed out. Confirmed pool rows are safe to drop once
    // the issue record exists; audit events are kept far longer because they are the compliance trail.
    'confirmed_pool_retention_days' => env('COREERP_CONFIRMED_POOL_RETENTION_DAYS', 30),
    'audit_retention_days' => env('COREERP_AUDIT_RETENTION_DAYS', 400),

    /*
     * Alamat antarmuka SigNoz, dipakai untuk menaruh tautan di dalam laporan kesalahan.
     *
     * Bukan alamat collector: yang ini dibuka manusia di peramban, yang itu menerima OTLP dan
     * porta keduanya berbeda. Kosong berarti laporannya tidak memuat tautan — benar untuk
     * pemasangan yang tidak punya SigNoz.
     */
    'signoz_url' => env('COREERP_SIGNOZ_URL'),

    /*
     * Pengiriman laporan kesalahan ke Discord.
     *
     * Kosong berarti mati, dan itulah bawaannya — termasuk pada pemasangan on-prem, yang
     * channel Discord-nya bukan milik kita. Lihat App\Support\Observabilitas\PengirimDiscord.
     */
    'discord' => [
        'webhook_url' => env('COREERP_DISCORD_WEBHOOK_URL'),

        // Ditulis apa adanya di depan ringkasan. `@everyone`, `@here`, `<@id_orang>`, atau
        // `<@&id_role>`; boleh digabung. Menulis `@nama` biasa tidak menjadi sebutan.
        'mention' => env('COREERP_DISCORD_MENTION', ''),

        // Jeda minimal antara dua kiriman untuk kesalahan yang sama. Lihat PenjedaKiriman —
        // ini yang memisahkan peringatan dari banjir. Nol mematikan penjedanya.
        'jeda_detik' => env('COREERP_DISCORD_JEDA_DETIK', 60),
    ],

    'operating_unit_types' => [
        'business_unit' => 'Business unit',
        'department' => 'Department',
        'cost_center' => 'Cost center',
        'value_stream' => 'Value stream',
        'retail_channel' => 'Retail channel',
    ],
];
