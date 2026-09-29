<?php

use ControlPlane\Models\User;

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
     * Operator masuk memakai akun yang sama dengan akun Core.
     *
     * Tabel `users` memang milik sisi pusat (lihat `OwnedByControlPlane` di Core), jadi membacanya dari sini
     * tidak menyeberangi batas mana pun. Yang tidak boleh: menulis ke sana. Pembuatan dan undangan
     * akun tetap di Core, dan konsol ini sengaja tidak punya satu pun jalur untuk itu.
     */
    'providers' => [
        // `people`: Eloquent yang hanya membaca akun orang, didaftarkan `AppServiceProvider`.
        'users' => [
            'driver' => 'people',
            'model' => User::class,
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,
];
