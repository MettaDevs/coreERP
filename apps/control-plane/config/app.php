<?php

return [
    'name' => env('APP_NAME', 'Pusat Admin'),
    'env' => env('APP_ENV', 'production'),

    // DSN Sentry untuk peramban (sesi dan kesalahan JavaScript). Bukan DSN server, yang boleh menunjuk
    // alamat internal. Kosong berarti peramban tidak mengirim apa pun.
    'sentry_browser_dsn' => env('SENTRY_BROWSER_DSN'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost:8001'),
    'timezone' => 'UTC',
    'locale' => 'id',
    'fallback_locale' => 'id',
    'faker_locale' => 'id_ID',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [],
    'maintenance' => [
        'driver' => 'file',
    ],
];
