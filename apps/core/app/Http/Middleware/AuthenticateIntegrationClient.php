<?php

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Models\IntegrationClient;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\Modules\TenantScope;
use Closure;
use Illuminate\Http\Request;
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
 */
final class AuthenticateIntegrationClient
{
    /** Atribut permintaan tempat id klien yang terautentikasi disimpan. */
    public const ATRIBUT = 'coreerp.integration_client_id';

    public function __construct(private readonly ActiveEnvironment $lingkungan) {}

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

        app()->instance(TenantScope::KUNCI, $client->tenant_id);
        $this->lingkungan->lupakan();
        if (! $this->lingkungan->outboundAllowed()) {
            abort(503, $this->lingkungan->refusalReason());
        }

        $request->attributes->set('coreerp.tenant_id', $client->tenant_id);
        $request->attributes->set(self::ATRIBUT, $client->id);

        // Sinyal hidup, bukan jejak audit — diperbarui paling sering sekali semenit supaya satu
        // baris tidak menjadi titik tulis panas pada setiap tarikan.
        if ($client->last_used_at === null || $client->last_used_at->lessThan(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function client(Request $request): ?IntegrationClient
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! str_contains($token, '.')) {
            return null;
        }

        [$id, $rahasia] = explode('.', $token, 2);
        if (! Str::isUlid($id) || $rahasia === '') {
            return null;
        }

        $client = IntegrationClient::query()
            ->whereKey($id)
            ->where('status', IntegrationClient::ACTIVE)
            ->first();

        return $client !== null && hash_equals($client->token_digest, IntegrationClient::digest($rahasia))
            ? $client
            : null;
    }
}
