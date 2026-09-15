<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\License\SiteLicense;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menahan pengguna tenant di halaman "Lisensi tidak berlaku" selama lisensi situs yang wajib tidak
 * berlaku — habis, hilang, atau bertanda tangan salah.
 *
 * Yang memutuskan "terkunci" bukan kelas ini melainkan {@see SiteLicense}. Kelas ini hanya memilih
 * siapa yang tetap lewat, dan bentuk penolakannya.
 *
 * ## Yang dilepas, dan alasan tiap satunya
 *
 * - **Tamu.** Halaman login harus tetap tampil; tamu belum melihat data apa pun, dan penjaga `auth`
 *   di belakang sudah menahan sisanya. Tamu juga tidak memicu pembacaan berkas lisensi sama sekali.
 * - **Login, tantangan dua langkah, pemulihan kata sandi, dan SSO.** Orang yang sedang masuk belum
 *   tentu tamu di tengah upacaranya — SSO yang menghubungkan akun kembali dengan sesi yang sudah ada.
 * - **Logout.** Orang yang terkunci harus selalu dapat keluar, misalnya untuk masuk sebagai provider.
 * - **Aset, `/up`, dan `.well-known`.** Bukan halaman. `/up` justru harus terus menjawab: pemantau
 *   perlu membedakan server yang terkunci dari server yang mati.
 * - **Akun provider.** Vendor harus dapat masuk ke server yang terkunci untuk memperbaikinya. Yang
 *   dibukakan hanya halaman Core; app tetap tertutup bagi provider juga, karena saringan app di
 *   `SiteLicense::allowsApp()` tidak mengenal pengecualian — vendor yang memperbaiki tidak perlu
 *   membuka rekam medis.
 *
 * ## Kenapa halamannya dirender di tempat, bukan dialihkan
 *
 * Tidak ada rute halaman kunci yang dituju, jadi tidak ada putaran pengalihan yang mungkin: setiap
 * alamat yang ditahan menjawab halaman kunci itu sendiri dengan 403. Lisensi yang diperpanjang agen
 * membuka kunci pada muat ulang berikutnya di alamat yang sama, tanpa orangnya harus tahu ke mana
 * kembali. Pengalihan ke satu rute kunci akan membuang alamat tujuannya dan membutuhkan pengecualian
 * yang dapat lupa ditulis — persis kurungan tanpa pintu yang dijaga `WajibGantiSandi`.
 *
 * ## Urutan pemeriksaannya
 *
 * Dari yang paling murah: tamu dan daftar yang dilepas tidak membaca apa pun, lisensi yang tidak wajib
 * tidak membaca berkas, dan pertanyaan "apakah ini provider" — satu query — hanya ditanyakan kepada
 * pemasangan yang memang terkunci. SaaS tidak membayar apa pun untuk middleware ini.
 */
final class EnforceSiteLicense
{
    /**
     * Rute yang tetap dapat dicapai selama terkunci.
     *
     * Nama rute, bukan path, dengan alasan yang sama dengan `WajibGantiSandi`: path berubah ketika
     * seseorang merapikan URL, dan penjaga yang memakai path diam-diam berhenti melepaskan apa pun.
     */
    private const RUTE_TERBUKA = [
        'login',
        'login.store',
        'logout',
        'two-factor.login',
        'two-factor.login.store',
        'password.*',
        'sso.*',
    ];

    /** Permintaan yang bukan halaman. */
    private const JALUR_TERBUKA = ['up', 'build/*', 'storage/*', '.well-known/*'];

    public function __construct(private readonly SiteLicense $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = $request->user();

        if (! $pengguna instanceof User) {
            return $next($request);
        }

        if ($request->routeIs(...self::RUTE_TERBUKA) || $request->is(...self::JALUR_TERBUKA)) {
            return $next($request);
        }

        if (! $this->license->isLocked()) {
            return $next($request);
        }

        if ($pengguna->providerAccess()->where('role', 'provider_admin')->exists()) {
            return $next($request);
        }

        // Pemanggil JSON tidak dapat membaca halaman. `api/*` disebut terpisah karena rute JSON milik
        // module dan Core di grup web tidak selalu dipanggil dengan `Accept: application/json`.
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'license_locked',
                'message' => 'Lisensi aplikasi ini tidak berlaku. Hubungi penyedia aplikasi.',
            ], Response::HTTP_FORBIDDEN);
        }

        $keadaan = $this->license->state();

        return Inertia::render('license-locked', [
            'status' => $keadaan->status,
            'validUntil' => $keadaan->validUntil,
        ])->toResponse($request)->setStatusCode(Response::HTTP_FORBIDDEN);
    }
}
