<?php

declare(strict_types=1);

namespace ControlPlane\Http\Middleware;

use Closure;
use ControlPlane\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya operator vendor yang boleh masuk ke konsol ini.
 *
 * Otoritasnya `provider_access.role = 'provider_admin'` — tabel yang sama yang sudah dipakai Core
 * untuk dua gate-nya. Sengaja tidak dibuat peran baru: peran baru berarti tempat kedua yang harus
 * dicabut ketika seseorang keluar dari tim, dan tempat kedua itulah yang biasanya terlupa.
 *
 * Penolakannya 404, bukan 403. Pengguna biasa tidak perlu tahu alamat ini ada sama sekali.
 */
class HanyaOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = $request->user();

        abort_unless($pengguna instanceof User && $pengguna->operator(), 404);

        return $next($request);
    }
}
