<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ExternalIdentity;
use App\Support\Sso\SharedIdentityProvider;
use App\Support\Sso\SsoFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Logout back-channel: penyedia memberi tahu bahwa seseorang keluar, dan setiap sesinya di sini ikut
 * berakhir.
 *
 * Seluruh sesi user itu dihapus, di semua alamat tenant, bukan satu. Penyedia hanya mengirim `sid`
 * bila ia punya — dan ID token-nya tidak pernah membawa `sid` — jadi tidak ada pengenal sesi yang
 * dapat dicocokkan dengan satu sesi tertentu di sini. `sub` yang ada.
 *
 * Dipanggil server penyedia, bukan peramban: tanpa sesi, tanpa token CSRF, dan jawabannya sesuai
 * OpenID Connect Back-Channel Logout 1.0 §2.8 — 200 bila berhasil, 400 bila token tidak sah.
 */
class SsoBackchannelLogoutController extends Controller
{
    public function __construct(private readonly SharedIdentityProvider $provider) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        if (! $this->provider->isConfigured()) {
            abort(404);
        }

        $logoutToken = $request->input('logout_token');

        try {
            if (! is_string($logoutToken) || $logoutToken === '') {
                throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Permintaan tidak membawa logout_token.');
            }

            $claims = $this->provider->verifyLogoutToken($logoutToken);
        } catch (SsoFailure $e) {
            Log::warning('SSO: logout back-channel ditolak.', ['alasan' => $e->reason, 'detail' => $e->getMessage()]);

            return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }

        $userId = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where('subject', (string) $claims['sub'])
            ->value('user_id');

        $ended = 0;

        if ($userId !== null) {
            $ended = DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $userId)
                ->delete();
        }

        Log::info('SSO: logout back-channel diterima.', ['user_id' => $userId, 'sesi_diakhiri' => $ended]);

        return response('', 200)->header('Cache-Control', 'no-store');
    }
}
