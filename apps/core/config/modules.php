<?php

declare(strict_types=1);

/**
 * Folder yang dipindai `ModuleRegistry` untuk menemukan module.
 *
 * ## Kenapa dua, dan kenapa yang kedua hanya ada saat test
 *
 * `modules/` di akar repo berisi module yang **dijual**. Bahan uji penjaga batas — `contoh-a` dan
 * `contoh-b` — dulu tinggal di sana juga, dan itu berarti satu-satunya hal yang memisahkan menu
 * "Contoh A" dari layar klien adalah sebuah pemangkasan saat membangun image: sebuah langkah yang
 * bisa terlewat, dan yang memang terlewat pada image ramping `deploy/perakit/Dockerfile` yang
 * sengaja tidak memangkas apa pun.
 *
 * Keduanya sekarang tinggal di `apps/core/tests/Fixtures/modules`, dan folder itu disebut di sini
 * **hanya** ketika `APP_ENV=testing`. Akibatnya bukan sekadar kerapian: bahan uji tidak lagi
 * bergantung pada pemangkasan untuk tidak ikut. Andai seluruh berkasnya tersalin ke image karena
 * kekeliruan, tidak ada satu pun jalan bagi runtime untuk memuatnya — registry tidak pernah
 * diberi tahu foldernya ada.
 *
 * Tahap akhir Dockerfile membuang `apps/core/tests`, jadi berkasnya pun memang tidak ikut. Dua
 * lapis, karena yang pertama bergantung pada disiplin orang.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Akar module
    |--------------------------------------------------------------------------
    |
    | Daftar folder, masing-masing dipindai dengan pola `<akar>/<penerbit>/<module>/app.yaml`.
    | Urutannya tidak menentukan apa pun: hasilnya selalu diurutkan menurut id module.
    |
    */

    'akar' => env('APP_ENV') === 'testing'
        ? [dirname(base_path(), 2).'/modules', base_path('tests/Fixtures/modules')]
        : [dirname(base_path(), 2).'/modules'],

];
