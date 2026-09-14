<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sso;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Http\Middleware\EndSessionAfterProviderLogout;
use ControlPlane\Models\ExternalIdentity;
use ControlPlane\Sso\IdentityProvider;
use ControlPlane\Sso\SsoFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Logout back-channel: penyedia memberi tahu bahwa seorang operator keluar atau dinonaktifkan.
 *
 * ## Kenapa penanda, bukan menghapus sesi
 *
 * Sesi konsol disimpan di berkas (`SESSION_DRIVER=file`), dan berkas sesi tidak dapat dicari
 * berdasarkan user. Yang dicatat di sini karena itu waktu keluarnya; `EndSessionAfterProviderLogout`
 * membandingkannya dengan waktu masuk sesi pada permintaan berikutnya dan mengakhiri sesi yang lebih
 * tua. Akibatnya sama — sesi itu tidak dapat dipakai lagi — hanya terjadinya pada klik berikutnya.
 */
class SsoBackchannelLogout extends Controller
{
    public function __construct(private readonly IdentityProvider $provider) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        abort_unless($this->provider->isConfigured(), 404);

        $token = $request->input('logout_token');

        try {
            if (! is_string($token) || $token === '') {
                throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Permintaan tidak membawa logout_token.');
            }

            $claims = $this->provider->verifyLogoutToken($token);
        } catch (SsoFailure $e) {
            Log::warning('Konsol SSO: logout back-channel ditolak.', ['alasan' => $e->reason, 'detail' => $e->getMessage()]);

            return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }

        $userId = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where('subject', (string) $claims['sub'])
            ->value('user_id');

        if ($userId !== null) {
            EndSessionAfterProviderLogout::markLoggedOut((int) $userId);
        }

        Log::info('Konsol SSO: logout back-channel diterima.', ['user_id' => $userId]);

        return response('', 200)->header('Cache-Control', 'no-store');
    }
}
