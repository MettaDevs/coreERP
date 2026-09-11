<?php

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'connection' => null,
    'table' => 'sessions',
    'store' => null,
    'lottery' => [2, 100],

    /*
     * Nama cookienya sengaja tidak diturunkan dari `APP_NAME`.
     *
     * Konsol ini dan Core berjalan di host yang sama saat pengembangan, dan cookie session Laravel
     * tidak dibedakan oleh porta. Dua aplikasi dengan nama cookie yang sama di `localhost` akan
     * saling menimpa session — gejalanya: masuk di satu aplikasi membuat aplikasi lain melempar
     * keluar, berganti-ganti, tanpa satu pun pesan kesalahan.
     */
    'cookie' => 'pusat_admin_session',
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE'),
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
];
