<?php

return [
    // Canonical official apps. Entitlements and module installations reference these IDs.
    'database' => 'core_erp',
    /*
     * Nama koneksi untuk tabel sisi pusat — identitas, pelanggan, daftar tenant, registry
     * environment, akses operator. Kosong berarti "ikut koneksi bawaan", dan itulah bawaannya.
     *
     * On-prem kosong selamanya: di sana tidak ada sisi pusat yang terpisah, dan Core memang harus
     * sanggup menjadi keseluruhannya. Yang membacanya trait App\Support\ControlPlane\OwnedByControlPlane.
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
     * begitu. Selama ia kosong, `ResolveEnvironment` tidak pernah menyala — bukan gagal, tidak
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
    'base_domain' => env('COREERP_BASE_DOMAIN'),

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
     * kehilangan alamatnya tanpa pernah tahu kenapa. Pendaftaran usaha membaca daftar yang sama,
     * jadi tenant BARU tidak pernah memperoleh label ini; tenant lama yang sudah memakainya tidak.
     *
     * `registry` adalah registry image Harbor, `registry.<base_domain>` — lihat deploy/registry.
     */
    'reserved_labels' => ['admin', 'www', 'api', 'registry'],

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
     * tenant yang belum ada. Lihat App\Http\Middleware\ControlPlaneOnly.
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
    /*
     * Proxy yang boleh dipercaya header `X-Forwarded-*`-nya. Kosong berarti tidak satu pun.
     *
     * Dibaca `AppServiceProvider`, bukan `bootstrap/app.php` — alasannya tertulis di sana, dan ia
     * bukan selera: closure middleware berjalan sebelum berkas env dimuat.
     */
    'trusted_proxies' => env('COREERP_TRUSTED_PROXIES'),

    /*
     * Penyedia identitas bersama — satu untuk semua tenant yang memilih mode `bersama`.
     *
     * Kosong berarti tidak ada SSO sama sekali, dan itu bawaannya: tombol masuk lewat SSO tidak
     * tampil, dan setiap rute `/sso/*` menjawab 404. Ketiganya harus terisi; separuh terisi
     * diperlakukan sama dengan kosong, bukan dicoba lalu gagal di tengah upacara OIDC.
     *
     * Rahasianya hidup di env, bukan di `tenant_identity_providers.setelan`: kolom itu sengaja hanya
     * memuat yang tidak rahasia, dan penyedia bersama memang milik penempatan, bukan milik tenant.
     * Penyedia milik tenant sendiri (`sendiri`) belum dibangun, begitu pula tempat rahasianya.
     */
    'sso' => [
        'issuer' => env('COREERP_SSO_ISSUER'),
        'client_id' => env('COREERP_SSO_CLIENT_ID'),
        'client_secret' => env('COREERP_SSO_CLIENT_SECRET'),

        /*
         * API pengelolaan penyedia — mencari pengguna, dan meminta penyedia mengirim email undangan.
         * Bukan bagian OIDC: alamatnya tidak ada di dokumen discovery, jadi ia satu-satunya alamat
         * penyedia yang harus disebut. Kosong berarti diturunkan dari issuer dengan akhiran `/api/v1`.
         *
         * Kredensialnya bawaan mengikuti pasangan di atas, karena penyedia memeriksa client id dan
         * secret Passport yang sama dan tidak mengenal kredensial mesin tersendiri. Dua kunci di
         * bawah ada supaya client kedua yang kelak didaftarkan cukup disetel, tanpa menyentuh kode.
         */
        'api_url' => env('COREERP_SSO_API_URL'),
        'api_client_id' => env('COREERP_SSO_API_CLIENT_ID'),
        'api_client_secret' => env('COREERP_SSO_API_CLIENT_SECRET'),

        /*
         * Nama aplikasi yang ditulis penyedia di email undangannya, dan berapa hari undangan berlaku.
         * Penyedia membatasi 1 sampai 30 hari; `expires_at` undangan diisi angka yang sama supaya
         * kalimat di email dan keadaan di sini tidak pernah berbeda.
         */
        'invitation_app_name' => env('COREERP_SSO_INVITATION_APP_NAME', 'CoreERP'),
        'invitation_days' => (int) env('COREERP_SSO_INVITATION_DAYS', 7),
    ],

    /*
     * Lisensi situs on-prem dikelola — sebuah kunci, bila diwajibkan.
     *
     * Berkasnya diterbitkan admin.erp, ditandatangani kunci rilis, lalu dipasang agen di server
     * pelanggan. Isinya menyebut app mana yang dibeli dan sampai kapan. Bila `required` menyala,
     * lisensi yang habis, hilang, atau bertanda tangan salah mengunci pengguna tenant, dan app yang
     * tidak tercantum tidak dapat dibuka. Alasan kenapa ia berubah dari tanda menjadi kunci, beserta
     * apa yang tetap terbuka, ada di App\Support\License\SiteLicense dan `docs/todo/lisensi-mengunci`.
     *
     * `required` berbawaan mati, dan itu disengaja: SaaS dan pemasangan beli-putus tidak pernah
     * terkunci. Yang menyalakannya `.env` server on-prem dikelola, ditulis agen saat pemasangan.
     * Diurai dengan `FILTER_VALIDATE_BOOLEAN` supaya `true`, `1`, dan `on` menyala, sedangkan kosong
     * dan `false` tidak — string `"false"` yang dibaca sebagai benar akan mengunci server yang tidak
     * pernah diminta terkunci.
     *
     * `path` kosong tanpa `required` berarti fitur ini mati. `path` kosong **dengan** `required`
     * berarti terkunci. Tanda tangannya dibaca dari `<path>.sig` di sebelahnya — satu jalur yang
     * disetel, bukan dua yang dapat menunjuk pasangan yang berbeda.
     */
    'license' => [
        'required' => filter_var(env('COREERP_LICENSE_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
        'path' => env('COREERP_LICENSE_PATH'),
        'public_key_path' => env('COREERP_LICENSE_PUBLIC_KEY_PATH'),
        // Berapa hari sebelum tanggal berakhir peringatannya mulai tampil. Lisensi berlaku 30 hari
        // dan diperpanjang otomatis ketika sisanya 10 hari, jadi dalam keadaan sehat peringatan ini
        // tidak pernah terlihat. Begitu ia tampil, perpanjangannya sudah gagal beberapa hari berturut-
        // turut — dan tujuh hari adalah waktu untuk memperbaikinya sebelum pelayanan terkunci.
        'warn_days' => 7,
    ],

];
