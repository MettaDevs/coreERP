<?php

/*
 * Antrean sengaja `sync`.
 *
 * Antrean sungguhan menuntut tabel `jobs` atau Redis, dan keduanya berarti konsol ini memiliki
 * bagian skema sendiri — persis yang dihindari. Pekerjaan panjang bukan miliknya: yang menjalankan
 * migration dan menyemai data adalah Core, dan konsol ini hanya memerintah lalu mencatat hasilnya.
 */
return [
    'default' => 'sync',
    'connections' => [
        'sync' => ['driver' => 'sync'],
    ],
];
