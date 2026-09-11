<?php

namespace App\Http\Middleware;

use App\Models\AppServiceCredential;
use App\Models\ModuleInstallation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAppService
{
    public function handle(Request $request, Closure $next): Response
    {
        $appId = $request->header('X-CoreERP-App-Id');
        $token = $request->header('X-CoreERP-Service-Token');
        $tenantId = $request->header('X-CoreERP-Tenant-Id');
        if (! is_string($appId) || ! is_string($token) || ! is_string($tenantId)) {
            abort(401, 'Kredensial aplikasi atau context tenant belum lengkap.');
        }

        $credential = $this->credential($appId, $tenantId, $token);
        if (! $credential || ! $this->isReadyForTenant($appId, $tenantId)) {
            abort(403, 'Aplikasi belum siap menggunakan layanan nomor untuk tenant ini.');
        }

        // Writing last_used_at on every call turns one row into a cluster-wide write hotspot, so it is only
        // refreshed once a minute. It is a liveness signal, not an audit record; the audit trail is elsewhere.
        if ($credential->last_used_at === null || $credential->last_used_at->lessThan(now()->subMinute())) {
            $credential->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('coreerp.app_id', $appId);
        $request->attributes->set('coreerp.tenant_id', $tenantId);

        return $next($request);
    }

    /**
     * Resolve the credential behind a token.
     *
     * A credential bound to a tenant only works for that tenant. A credential with no tenant keeps the older
     * shared-app behaviour so existing deployments keep working until their tokens are reissued.
     *
     * Modern tokens are `<credential_id>.<secret>`, so the id selects exactly one row and the secret is compared as
     * a constant-time digest. Legacy tokens have no id and still need the bcrypt scan, which is why reissuing them
     * matters: bcrypt measured 251ms per call and runs once per stored credential.
     */
    private function credential(string $appId, string $tenantId, string $token): ?AppServiceCredential
    {
        $scoped = fn ($query) => $query
            ->where('app_id', $appId)
            ->where('status', 'active')
            ->where(fn ($inner) => $inner->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));

        if (str_contains($token, '.')) {
            [$credentialId, $secret] = explode('.', $token, 2);
            $candidate = AppServiceCredential::query()->tap($scoped)->whereKey($credentialId)->first();

            return $candidate?->token_digest !== null
                && hash_equals($candidate->token_digest, AppServiceCredential::digest($secret))
                    ? $candidate
                    : null;
        }

        return AppServiceCredential::query()->tap($scoped)->whereNotNull('secret_hash')->get()
            ->first(fn (AppServiceCredential $candidate): bool => Hash::check($token, (string) $candidate->secret_hash));
    }

    /**
     * Kesiapan sekarang berarti satu hal: tenant berhak atas app ini **dan** app-nya tercatat
     * terpasang sebagai module untuk tenant itu.
     *
     * Penentu sebelumnya — artifact ditempatkan, migration berhasil, runtime dinyatakan siap —
     * milik jalur hosting container, dan ia ikut dibuang bersama jalur itu. Mempertahankannya
     * berarti menolak setiap pemanggil, karena tidak ada lagi yang menulis `app_placements`.
     */
    private function isReadyForTenant(string $appId, string $tenantId): bool
    {
        return DB::table('tenant_app_entitlements as entitlements')
            ->join('core_module_installations as installations', function ($join) use ($appId): void {
                $join->on('installations.tenant_id', '=', 'entitlements.tenant_id')
                    ->where('installations.module_id', '=', $appId)
                    ->where('installations.status', '=', ModuleInstallation::STATUS_INSTALLED);
            })
            ->where('entitlements.tenant_id', $tenantId)->where('entitlements.app_id', $appId)
            ->where('entitlements.status', 'active')->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->exists();
    }
}
