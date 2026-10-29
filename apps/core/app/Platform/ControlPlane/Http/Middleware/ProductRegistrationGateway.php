<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ProductRegistrationGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('managed_products.registration.gateway_token');
        abort_unless(is_string($expected) && strlen($expected) >= 32, 404);
        $supplied = $request->bearerToken();
        abort_unless(is_string($supplied) && hash_equals($expected, $supplied), 401);
        $session = $request->header('X-Registration-Session');
        $key = is_string($session) && strlen($session) >= 32 && strlen($session) <= 128
            ? 'product-registration.session.'.hash('sha256', $session)
            : 'product-registration.auth.'.$request->path().'|'.$request->ip();
        $limit = is_string($session) ? 120 : 30;
        if ($request->isMethod('POST')) {
            abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, 'Terlalu banyak permintaan. Silakan tunggu sebentar.');
            RateLimiter::hit($key, 60);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
