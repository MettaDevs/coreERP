<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Installer;

use ControlPlane\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Berkas yang dibutuhkan satu perintah pasang di server klien, disajikan konsol ini sendiri.
 *
 * ## Kenapa dari sini, bukan dari GitHub
 *
 * Repo kembali privat, dan server klien tidak punya — dan tidak boleh punya — kredensial GitHub.
 * Satu-satunya alamat yang pasti dapat dijangkau server klien yang sedang dipasang adalah alamat yang
 * juga dipakainya untuk mendaftar: admin.erp. Rancangannya di `docs/todo/pasang-satu-perintah`, PS-07.
 *
 * ## Berkas yang disajikan sama dengan agen yang dipasang konsol ini
 *
 * Berkasnya dibaca dari susunan repo di sebelah aplikasi ini (`deploy/agent`, `scripts/update.sh`),
 * dan `apps/control-plane/Dockerfile` menyalinnya ke image konsol dari commit yang sama. Agen yang
 * dipasang karena itu selalu versi yang kontrak API-nya dipahami konsol yang menyajikannya — bukan
 * versi terbaru di cabang mana pun.
 *
 * ## Tanpa login, dan itu disengaja
 *
 * Isinya bukan rahasia: skrip pasang, agen bash, unit systemd, templat setelan tanpa isi, dan kunci
 * **publik** rilis. Yang membuat pemasangan sah adalah token pendaftaran berumur satu jam yang
 * ditempel operator, bukan kerahasiaan berkas ini. Rute-rutenya tetap dibatasi laju.
 *
 * ## Kunci publik rilis dipercaya pada pemasangan pertama
 *
 * Tidak ada jalur kedua yang independen untuk mengantarkannya setelah repo privat. Agen memakunya
 * begitu terpasang dan menolak penggantian diam-diam; yang dibeli adalah satu jendela kepercayaan,
 * saat teknisi berada di lokasi, bukan kepercayaan pada setiap pembaruan sesudahnya.
 */
final class InstallerFiles extends Controller
{
    /** Nama berkas agen yang boleh diminta, dan jalurnya di susunan repo. */
    public const FILES = [
        'coreerp-agent' => 'deploy/agent/coreerp-agent',
        'coreerp-agent.service' => 'deploy/agent/coreerp-agent.service',
        'coreerp-agent.timer' => 'deploy/agent/coreerp-agent.timer',
        'env.template' => 'deploy/agent/env.template',
        'update.sh' => 'scripts/update.sh',
    ];

    /**
     * Isian di `pasang.sh` yang diganti alamat konsol ini.
     *
     * Alamatnya ditanam saat disajikan, bukan diminta sebagai argumen, supaya perintah yang ditempel
     * operator tetap satu baris pendek dan tidak dapat diarahkan ke konsol lain oleh salah ketik.
     */
    public const ADMIN_URL_PLACEHOLDER = '@@COREERP_ADMIN_URL@@';

    public function installer(): Response
    {
        $adminUrl = rtrim((string) config('app.url'), '/');

        if (! str_starts_with($adminUrl, 'https://') && ! app()->environment('local', 'testing')) {
            return $this->unavailable('APP_URL konsol ini belum berupa alamat https, jadi skrip pasang tidak dapat menunjuk konsol yang benar.');
        }

        $body = $this->read('deploy/agent/pasang.sh');

        if ($body === null) {
            return $this->unavailable('Skrip pasang tidak ikut terpasang di konsol ini.');
        }

        return $this->plain(str_replace(self::ADMIN_URL_PLACEHOLDER, $adminUrl, $body));
    }

    public function file(string $berkas): Response
    {
        $body = isset(self::FILES[$berkas]) ? $this->read(self::FILES[$berkas]) : null;

        if ($body === null) {
            abort(404);
        }

        return $this->plain($body);
    }

    public function releaseKey(): Response
    {
        $path = config('sites.release_public_key_path');
        $pem = is_string($path) && $path !== '' && is_readable($path) ? file_get_contents($path) : false;

        // Isinya diperiksa sebelum disajikan. Berkas yang tertukar — kunci privat, sertifikat, atau berkas
        // kosong — yang terlanjur dipaku agen hanya dapat dilepas dengan menyentuh server klien.
        if (! is_string($pem) || openssl_pkey_get_public($pem) === false || str_contains($pem, 'PRIVATE KEY')) {
            return $this->unavailable('Kunci publik rilis belum disetel di konsol ini (CONSOLE_RELEASE_PUBLIC_KEY_PATH).');
        }

        return $this->plain($pem);
    }

    private function read(string $pathInRepo): ?string
    {
        $root = config('sites.installer_source_root');
        $path = rtrim(is_string($root) ? $root : base_path('../..'), '/\\').'/'.$pathInRepo;
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return is_string($content) && $content !== '' ? $content : null;
    }

    private function plain(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * 503, bukan 404: berkasnya memang seharusnya ada, dan yang salah setelan konsol ini.
     *
     * Kalimatnya sampai ke terminal teknisi lewat `curl -f`, jadi ia menyebut apa yang harus disetel.
     */
    private function unavailable(string $message): Response
    {
        return response($message."\n", 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
