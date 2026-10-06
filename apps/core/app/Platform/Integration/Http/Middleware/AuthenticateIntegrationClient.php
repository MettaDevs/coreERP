<?php

namespace App\Platform\Integration\Http\Middleware;

use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Support\ActiveEnvironment;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Integration\Support\IntegrationClientAccounts;
use App\Platform\Modules\Http\Middleware\AuthenticateAppService;
use App\Platform\Modules\Support\TenantScope;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjaga rute `internal/v1` yang dipanggil sistem di luar CoreERP (K-03, TODO 4.2).
 *
 * Tokennya `Authorization: Bearer <id klien>.<rahasia>`. Id memilih tepat satu baris, lalu rahasia
 * dibandingkan sebagai digest dengan `hash_equals` — pola yang sama dengan `AuthenticateAppService`.
 *
 * Tenant **selalu** diambil dari klien, tidak pernah dari URL, query, body, atau header. Pemanggil
 * yang mengirim `X-CoreERP-Tenant-Id` tetap dilayani untuk tenant miliknya sendiri, bukan untuk
 * tenant yang ia sebut.
 *
 * Di salinan sandbox, seluruh rute ini menjawab 503 (TODO 4.3). Salinan produksi membawa klien
 * integrasi yang sama, dan tanpa penjagaan ini server uji akan menyajikan posting yang dibukukan
 * finance produksi.
 *
 * Klien tidak masuk lewat guard, jadi event `Authenticated` yang memasang pelaku tidak pernah menyala di
 * sini. Pelakunya dipasang langsung: akun aplikasi klien itu ({@see IntegrationClientAccounts}), supaya
 * penulisannya tercatat atas nama klien di kolom jejak dan log perubahan, bukan sebagai sistem.
 */
final class AuthenticateIntegrationClient
{
    /** Atribut permintaan tempat id klien yang terautentikasi disimpan. */
    public const ATTRIBUTE = 'coreerp.integration_client_id';

    public function __construct(
        private readonly ActiveEnvironment $environment,
        private readonly IntegrationClientAccounts $accounts,
    ) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $client = $this->client($request);
        $environment = $request->attributes->get('coreerp.environment');
        // Di SaaS alamat sudah menunjuk satu tenant, dan databasenya sudah dipilih dari alamat itu.
        // Token tenant lain yang kebetulan cocok di sini tetap ditolak dengan pesan yang sama,
        // supaya penolakannya tidak membocorkan tenant mana pemilik token itu.
        if ($client === null || ($environment instanceof Environment && $environment->tenant_id !== $client->tenant_id)) {
            abort(401, 'Token klien integrasi tidak sah atau sudah dicabut.');
        }

        if (! $client->allowsIp($request->ip())) {
            abort(403, 'Alamat asal permintaan tidak termasuk alamat yang diizinkan untuk klien integrasi ini.');
        }

        foreach ($scopes as $scope) {
            if (! $client->hasScope($scope)) {
                abort(403, sprintf('Klien integrasi ini tidak punya izin %s.', $scope));
            }
        }

        app()->instance(TenantScope::KEY, $client->tenant_id);
        $this->environment->forget();
        if (! $this->environment->outboundAllowed()) {
            abort(503, $this->environment->refusalReason());
        }

        $request->attributes->set('coreerp.tenant_id', $client->tenant_id);
        $request->attributes->set(self::ATTRIBUTE, $client->id);

        // Klien lama yang lahir sebelum akun aplikasi ada mendapat akunnya pada panggilan pertama.
        AuditActor::set($client->user_id ?? $this->accounts->ensure($client));

        try {
            // Sinyal hidup, bukan jejak audit — diperbarui paling sering sekali semenit supaya satu
            // baris tidak menjadi titik tulis panas pada setiap tarikan.
            if ($client->last_used_at === null || $client->last_used_at->lessThan(now()->subMinute())) {
                $client->forceFill(['last_used_at' => now()])->saveQuietly();
            }

            // Jatah per klien baru dipakai sesudah id token diverifikasi; batas IP ada di middleware rute sebelumnya.
            $limit = (int) config('coreerp.integration_api_rate_limit', 120);
            $key = 'integration-client:'.$client->id;
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $retryAfter = RateLimiter::availableIn($key);
                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $limit,
                    'X-RateLimit-Remaining' => 0,
                    'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->timestamp,
                ]);
            }
            RateLimiter::hit($key, 60);

            $response = $next($request);
            $response->headers->add([
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => RateLimiter::retriesLeft($key, $limit),
            ]);

            return $response;
        } finally {
            // Pelaku milik permintaan ini saja; koneksi yang dipakai ulang tidak boleh membawanya.
            AuditActor::clear();
        }
    }

    private function client(Request $request): ?IntegrationClient
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! str_contains($token, '.')) {
            return null;
        }

        [$id, $secret] = explode('.', $token, 2);
        if (! Str::isUlid($id) || $secret === '') {
            return null;
        }

        $client = IntegrationClient::query()
            ->whereKey($id)
            ->where('status', IntegrationClient::ACTIVE)
            ->first();

        return $client !== null && hash_equals($client->token_digest, IntegrationClient::digest($secret))
            ? $client
            : null;
    }
}
