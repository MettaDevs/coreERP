<?php

declare(strict_types=1);

/*
 * Masuk ke konsol operator lewat penyedia identitas bersama.
 *
 * Klien SSO-nya BUKAN klien milik Core. Konsol didaftarkan sebagai aplikasi tersendiri di penyedia
 * ("CoreERP Admin") dengan alamat balik dan grup yang boleh masuk sendiri, jadi akses ke panel yang
 * dapat membuat tenant dapat dibatasi terpisah dari akses ke tenant itu sendiri. Di server, kedua
 * klien berbagi satu berkas env — itu sebabnya namanya berawalan `CONSOLE_`, bukan `COREERP_`.
 *
 * Kosong berarti tidak ada SSO: tombolnya tidak tampil, rute `/sso/*` menjawab 404, dan konsol
 * bekerja persis seperti sebelumnya.
 */
return [
    'issuer' => env('CONSOLE_SSO_ISSUER'),
    'client_id' => env('CONSOLE_SSO_CLIENT_ID'),
    'client_secret' => env('CONSOLE_SSO_CLIENT_SECRET'),

    /*
     * Pintu kata sandi. Nyala secara bawaan, dan bukan karena kelalaian.
     *
     * Menghubungkan akun SSO menuntut operator masuk dengan kata sandinya lebih dulu — itu satu-
     * satunya bukti bahwa ia pemilik akun konsol. Mematikan pintu ini sebelum setiap operator
     * menghubungkan SSO-nya berarti mengunci mereka semua di luar.
     *
     * Sesudah semuanya terhubung, setel `false` di server. Pintu itu lalu hanya terbuka bagi akun
     * darurat di bawah — dipakai ketika penyedia identitas sendiri sedang mati, dengan setiap
     * pemakaiannya tercatat sebagai peringatan.
     */
    'password_login' => filter_var(env('CONSOLE_PASSWORD_LOGIN', true), FILTER_VALIDATE_BOOL),

    /** Email yang tetap boleh masuk dengan kata sandi ketika pintu di atas ditutup. Dipisah koma. */
    'break_glass_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => mb_strtolower(trim($email)),
        explode(',', (string) env('CONSOLE_BREAK_GLASS_EMAILS', '')),
    ))),
];
