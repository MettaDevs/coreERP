<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCoreErpEvent
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header('X-CoreERP-Event-Timestamp');
        $signature = $request->header('X-CoreERP-Event-Signature');
        $key = (string) config('services.coreerp.context_signing_key');
        abort_unless(is_string($timestamp) && ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300 && is_string($signature) && $key !== '', 401);
        abort_unless(hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $key), $signature), 401);

        return $next($request);
    }
}
