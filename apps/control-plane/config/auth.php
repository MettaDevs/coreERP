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
     * Tabel `users` memang milik sisi pusat (lihat `MilikPusat` di Core), jadi membacanya dari sini
     * tidak menyeberangi batas mana pun. Yang tidak boleh: menulis ke sana. Pembuatan dan undangan
     * akun tetap di Core, dan konsol ini sengaja tidak punya satu pun jalur untuk itu.
     */
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,
];
