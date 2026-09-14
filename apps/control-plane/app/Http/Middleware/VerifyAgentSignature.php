<?php

declare(strict_types=1);

namespace ControlPlane\Http\Middleware;

use Closure;
use ControlPlane\Sites\SignatureInvalid;
use ControlPlane\Sites\SignedAgentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menerima permintaan agen hanya bila tanda tangannya sah, lalu menitipkan situsnya ke permintaan.
 *
 * Controller di belakangnya membaca situs dari atribut `site`, tidak pernah dari isi permintaan.
 * Id situs di isi laporan tetap diperiksa sama dengan situs penandatangan — tetapi yang menentukan
 * siapa pemanggilnya adalah kuncinya, bukan apa yang ia tulis tentang dirinya sendiri.
 */
final class VerifyAgentSignature
{
    public const SITE = 'coreerp.site';

    public function __construct(private readonly SignedAgentRequest $signatures) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $site = $this->signatures->verify($request);
        } catch (SignatureInvalid $e) {
            Log::warning('Agen situs: tanda tangan ditolak.', ['sebab' => $e->getMessage(), 'path' => $request->path()]);

            return response()->json(['error' => 'signature_invalid'], 401);
        }

        $request->attributes->set(self::SITE, $site);

        return $next($request);
    }
}
