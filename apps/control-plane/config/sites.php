<?php

declare(strict_types=1);

/*
 * Situs: server milik klien yang dikelola dari konsol ini lewat agen.
 *
 * Rancangannya di `docs/todo/on-prem-dikelola/README.md`, kontrak API agennya di
 * `contracts/openapi-agent.yaml`.
 */
return [
    /*
     * Kunci publik rilis, dalam PEM. Dipakai memeriksa berkas rilis sebelum didaftarkan.
     *
     * Kunci **privat** rilis tidak pernah ada di konsol ini. Itu yang membatasi akibat konsol yang
     * dibobol: penyerang dapat memilih rilis yang sudah kita terbitkan, tetapi tidak dapat membuat
     * rilis baru. Agen di server klien memeriksa tanda tangan yang sama dengan salinan kunci publik
     * yang ia terima lewat jalur lain, bukan dari konsol ini.
     */
    'release_public_key_path' => env('CONSOLE_RELEASE_PUBLIC_KEY_PATH'),

    /*
     * Token yang dipakai alur rilis untuk mendaftarkan berkas rilis. Kosong berarti pendaftaran rilis
     * ditolak seluruhnya — bukan terbuka.
     */
    'release_token' => env('CONSOLE_RELEASE_TOKEN'),

    /*
     * Pasangan kunci lisensi. Berbeda dari kunci rilis, kunci privat lisensi memang tinggal di sini:
     * lisensi palsu hanya menyembunyikan peringatan di layar klien, tidak membuka apa pun, jadi ia
     * tidak layak dijaga seketat kunci rilis.
     */
    'license_private_key_path' => env('CONSOLE_LICENSE_PRIVATE_KEY_PATH'),
    'license_public_key_path' => env('CONSOLE_LICENSE_PUBLIC_KEY_PATH'),

    /*
     * Dari commit mana skrip pasang dan kunci publik rilis diambil. Keduanya diambil dari repo di
     * GitHub, bukan dari konsol ini: kunci pemverifikasi yang diantar oleh pihak yang juga mengantar
     * perintahnya tidak memverifikasi apa pun terhadap pihak itu.
     */
    'agent_source' => env('CONSOLE_AGENT_SOURCE', 'https://raw.githubusercontent.com/MettaDevs/coreERP'),
    'agent_source_ref' => env('CONSOLE_AGENT_SOURCE_REF', 'main'),

    'interval_seconds' => 60,

    /* Selisih jam terbesar yang diterima pada tanda tangan permintaan agen. */
    'signature_skew_seconds' => 300,

    /* Tenggat operasi yang sedang dikerjakan agen; diperpanjang setiap laporan langkah. */
    'lease_minutes' => 15,

    /* Permintaan yang tidak pernah diambil agen berhenti menunggu sesudah ini. */
    'request_expiry_days' => 7,

    /*
     * Umur token pendaftaran. Online cukup satu jam: perintahnya dijalankan saat itu juga. Offline
     * tiga puluh hari, karena paketnya dibawa dengan flashdisk ke lokasi klien.
     */
    'enrollment_token_minutes' => [
        'online' => 60,
        'offline' => 60 * 24 * 30,
    ],

    /* Situs online yang tidak melapor selama ini ditampilkan tertinggal, bukan sehat. */
    'stale_after_seconds' => 180,
];
