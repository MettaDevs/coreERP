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
     * Pasangan kunci lisensi. Berbeda dari kunci rilis, kunci privat lisensi memang harus tinggal di
     * sini: perpanjangan otomatis menandatangani lisensi di dalam jawaban laporan agen, tanpa manusia
     * yang dapat diminta membawa kunci dari tempat lain.
     *
     * Sejak lisensi mengunci (`docs/todo/lisensi-mengunci`), kunci ini yang memutuskan app mana yang
     * boleh dibuka di server klien. Konsol yang dibobol dapat menerbitkan lisensi untuk app yang tidak
     * dibeli — jadi berkasnya dijaga seperti rahasia produksi lain, bukan seperti setelan tampilan.
     */
    'license_private_key_path' => env('CONSOLE_LICENSE_PRIVATE_KEY_PATH'),
    'license_public_key_path' => env('CONSOLE_LICENSE_PUBLIC_KEY_PATH'),

    /*
     * Masa berlaku lisensi yang diterbitkan tanpa tanggal dari operator.
     *
     * Tiga puluh hari, bukan setahun: sewa yang berhenti harus mengunci aplikasi dalam hitungan
     * minggu. Ongkosnya ditanggung server yang tidak dapat menghubungi admin.erp selama tiga minggu
     * lebih — ia ikut terkunci.
     */
    'license_valid_days' => 30,

    /*
     * Lisensi baru disertakan di jawaban laporan begitu sisa masa lisensi terpasang sebanyak ini atau
     * kurang.
     *
     * Sepuluh, bukan tujuh: peringatan di Core tampil tujuh hari sebelum habis. Perpanjangan yang
     * mulai lebih awal dari peringatan itu selesai sebelum pengguna klinik pernah melihatnya, dan
     * gangguan jaringan selama tiga hari pertama belum terlihat oleh siapa pun di klinik.
     */
    'license_renew_before_days' => 10,

    /*
     * Jeda terpendek antara dua penerbitan untuk situs yang sama, dan jeda sebelum mencoba lagi
     * sesudah penerbitan gagal.
     *
     * Agen melapor setiap menit, dan laporan sesudah lisensi dikirim masih membawa tanggal lama bila
     * agen gagal memasangnya. Tanpa jeda, setiap laporan itu melahirkan lisensi baru dan satu baris
     * audit baru; dengan Core yang mati, setiap laporan menjadi satu panggilan yang pasti gagal.
     */
    'license_renew_cooldown_minutes' => 60,

    'interval_seconds' => 60,

    /* Selisih jam terbesar yang diterima pada tanda tangan permintaan agen. */
    'signature_skew_seconds' => 300,

    /* Tenggat operasi yang sedang dikerjakan agen; diperpanjang setiap laporan langkah. */
    'lease_minutes' => 15,

    /* Permintaan yang tidak pernah diambil agen berhenti menunggu sesudah ini. */
    'request_expiry_days' => 7,

    /* Umur token pendaftaran. Satu jam cukup: perintah pasangnya dijalankan saat itu juga. */
    'enrollment_token_minutes' => 60,

    /* Situs yang tidak melapor selama ini ditampilkan tertinggal, bukan sehat. */
    'stale_after_seconds' => 180,

    /*
     * Akar susunan repo tempat berkas pemasang dibaca (`deploy/agent`, `scripts/update.sh`).
     *
     * Di laptop pengembang dan di image konsol keduanya dua tingkat di atas aplikasi ini — Dockerfile
     * menirukan susunan itu. Setelan ini ada hanya supaya test dapat menunjuk salinan berkas tiruan;
     * sengaja tanpa env, karena tidak ada alasan produksi untuk menyajikan berkas dari tempat lain.
     */
    'installer_source_root' => base_path('../..'),

    /*
     * Registry image Harbor (`docs/todo/registry-harbor`, `deploy/registry`).
     *
     * Dua alamat untuk satu registry, dan keduanya disengaja:
     *
     * - `registry_host` adalah nama yang diberikan kepada agen di server klien. Ia tidak pernah tersimpan di
     *   server klien — datang lagi di setiap operasi — jadi registry dapat pindah tanpa menyentuh klien.
     * - `registry_api_url` adalah jalan konsol ini ke API Harbor lewat jaringan Docker internal. Jalur admin
     *   Harbor di internet dapat dibatasi daftar IP, dan panggilan konsol tidak perlu keluar mesin.
     *
     * Rahasia robot sistem tidak di sini melainkan di `console_settings`, terenkripsi, lewat
     * `php artisan registry:robot-sistem` — lihat `ControlPlane\Registry\RegistrySettings`.
     */
    'registry_host' => env('CONSOLE_REGISTRY_HOST', 'registry.erp.grenery.xyz'),
    'registry_api_url' => env('CONSOLE_REGISTRY_API_URL', 'http://harbor-registry-proxy:8080'),
    'registry_project' => 'coreerp',

    /*
     * Umur robot situs dalam hari, satuan terkecil yang diterima Harbor. Batas atasnya saja: robot dihapus
     * begitu operasinya ditutup, dan umur ini hanya penjaga bila penghapusan itu tidak pernah berhasil.
     */
    'registry_robot_days' => 1,
];
