<?php

declare(strict_types=1);

namespace App\Platform\Identity\Http\Middleware;

use App\Platform\Identity\Support\SessionRevocation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EndSessionAfterProviderLogout
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessions = app(SessionRevocation::class);
        $user = $sessions->enabled() ? $request->user() : null;
        if ($user !== null && $sessions->revoked($request, $user->getAuthIdentifier())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Sesi Anda sudah berakhir. Silakan masuk kembali.'], 401)
                : redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
