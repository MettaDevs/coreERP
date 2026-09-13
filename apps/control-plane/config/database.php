<?php

/*
 * Satu koneksi saja, dan ia menunjuk database yang sama dengan Core.
 *
 * Itu bukan kompromi sementara yang lupa dirapikan melainkan keadaan Irisan 1 apa adanya: batasnya
 * sudah ditarik di tingkat kode (penanda `OwnedByControlPlane` di Core), databasenya belum dipisah. Ketika
 * ia dipisah kelak, yang berubah hanya isi `DB_DATABASE` di berkas env ini — bukan satu baris pun
 * kode di sini.
 */
return [
    /*
     * Dibaca dari env, dan itu bukan kelengkapan melainkan perbaikan cacat.
     *
     * Nilainya sempat dipaku `'pgsql'`. Akibatnya `DB_CONNECTION=pgsql_test` pada `phpunit.xml`
     * **diabaikan sepenuhnya**, dan seluruh suite konsol ini — yang membangun ulang skema Core
     * lewat `migrate:fresh` — berjalan di atas database kerja pengembang. Ia tetap hijau, karena
     * skemanya memang terbentuk; yang hilang hanya isinya.
     *
     * Penjaga di `Tests\TestCase::setUpTraits()` yang menemukannya, pada hari ia dipindah ke titik
     * yang dapat membaca `config()` yang sebenarnya. Sebelum itu ia membaca `getenv('DB_CONNECTION')`
     * — yang memang berisi `pgsql_test`, persis nilai yang tidak pernah dipakai siapa pun.
     *
     * Pelajarannya: memeriksa **niat** (apa yang disetel) berbeda dari memeriksa **akibat** (apa
     * yang benar-benar dipakai), dan hanya yang kedua yang menjaga apa pun.
     */
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'core_erp'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        /*
         * Koneksi test, dan ia menunjuk skema yang sama dengan suite Core (`coreerp_test`).
         *
         * Bukan database terpisah, karena yang diuji di sini justru kemampuan konsol ini membaca dan
         * menulis tabel milik Core. Suite di bawah `tests/` membangun skemanya sendiri dari folder
         * migration Core — satu-satunya sumbernya — sehingga ia tidak menunggu suite Core berjalan
         * lebih dulu dan tidak pernah menguji skema yang sudah basi.
         */
        'pgsql_test' => [
            'driver' => 'pgsql',
            'host' => env('DB_TEST_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_TEST_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_TEST_DATABASE', env('DB_DATABASE', 'core_erp')),
            'username' => env('DB_TEST_USERNAME', env('DB_USERNAME')),
            'password' => env('DB_TEST_PASSWORD', env('DB_PASSWORD')),
            'charset' => 'utf8',
            'search_path' => env('DB_TEST_SCHEMA', 'coreerp_test'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
