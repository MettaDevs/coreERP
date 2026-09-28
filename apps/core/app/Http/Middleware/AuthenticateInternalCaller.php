<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Untuk rute `internal/v1` yang dibaca dua jenis pemanggil: module lewat kredensial app, dan sistem
 * luar lewat klien integrasi.
 *
 * `GET /operating-units` contohnya: module human-resources sudah memakainya, dan pembaca feed
 * posting finance menyinkronkan tabel penerjemahnya dari rute yang sama (K-07). Dua rute untuk data
 * yang sama berarti dua kontrak yang kelak menyimpang.
 *
 * Yang memilih jalurnya header yang dikirim: `X-CoreERP-App-Id` berarti kredensial app, selain itu
 * klien integrasi dengan cakupan yang diberikan sebagai parameter. Keduanya tetap menjalankan
 * seluruh pemeriksaannya sendiri; kelas ini tidak melonggarkan satu pun.
 */
final class AuthenticateInternalCaller
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        if ($request->hasHeader('X-CoreERP-App-Id')) {
            return app(AuthenticateAppService::class)->handle($request, $next);
        }

        return app(AuthenticateIntegrationClient::class)->handle($request, $next, ...$scopes);
    }
}
