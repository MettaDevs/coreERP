<?php

declare(strict_types=1);

namespace ControlPlane\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengakhiri sesi konsol yang berdiri sebelum operatornya keluar di penyedia identitas.
 *
 * Pasangan `SsoBackchannelLogout`, yang hanya dapat mencatat waktu keluar karena sesi konsol ada di
 * berkas. Sesi yang tidak mencatat waktu masuknya — lahir sebelum penjaga ini ada — diperlakukan
 * sebagai lebih tua dari penanda apa pun.
 */
class EndSessionAfterProviderLogout
{
    public static function markLoggedOut(int $userId): void
    {
        Cache::put(self::key($userId), now()->getTimestamp(), now()->addDays(30));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Auth::id();

        if ($userId !== null) {
            $loggedOutAt = Cache::get(self::key((int) $userId));
            $loginAt = (int) $request->session()->get('login_at', 0);

            if (is_int($loggedOutAt) && $loginAt <= $loggedOutAt) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login');
            }
        }

        return $next($request);
    }

    private static function key(int $userId): string
    {
        return 'console.sso.logged_out_at.'.$userId;
    }
}
