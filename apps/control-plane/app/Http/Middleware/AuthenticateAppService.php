<?php

namespace App\Http\Middleware;

use App\Models\AppServiceCredential;
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

    private function isReadyForTenant(string $appId, string $tenantId): bool
    {
        return DB::table('tenant_app_entitlements as entitlements')
            ->join('tenant_deployments as deployments', 'deployments.tenant_id', '=', 'entitlements.tenant_id')
            ->join('app_placements as placements', 'placements.placement', '=', 'deployments.placement')
            ->where('entitlements.tenant_id', $tenantId)->where('entitlements.app_id', $appId)
            ->where('entitlements.status', 'active')->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->where('deployments.status', 'active')->where('placements.app_id', $appId)
            ->whereColumn('placements.profile', 'deployments.profile')
            ->where('placements.artifact_status', 'placed')->where('placements.migration_status', 'succeeded')
            ->where('placements.runtime_status', 'ready')->whereNotNull('placements.ready_at')->exists();
    }
}
