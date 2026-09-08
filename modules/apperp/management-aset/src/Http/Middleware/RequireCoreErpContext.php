<?php

namespace Modules\Apperp\ManagementAset\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireCoreErpContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $this->verifiedPayload((string) $request->bearerToken());
        if (! $payload) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sesi aplikasi tidak valid.']], 401);
        }

        $request->attributes->set('coreerp.tenant_id', $payload['tenant_id']);
        $request->attributes->set('coreerp.legal_entity_id', $payload['legal_entity_id'] ?? null);
        $request->attributes->set('coreerp.org_unit_id', $payload['org_unit_id'] ?? null);
        $request->attributes->set('coreerp.user_id', (string) $payload['sub']);
        $request->attributes->set('coreerp.permissions', $payload['permissions']);
        $request->attributes->set('coreerp.data_policies', $payload['data_policies']);

        return $next($request);
    }

    /** @return array<string, mixed>|null */
    private function verifiedPayload(string $token): ?array
    {
        $key = (string) config('services.coreerp.context_signing_key');
        $parts = explode('.', $token);
        if (strlen($key) < 32 || count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, $key, true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $headerData = $this->decode($header);
        $data = $this->decode($payload);
        if (($headerData['alg'] ?? null) !== 'HS256'
            || ($data['iss'] ?? null) !== 'coreerp'
            || ($data['aud'] ?? null) !== config('services.coreerp.app_id')
            || ! is_int($data['exp'] ?? null)
            || $data['exp'] < time()
            || ! is_int($data['iat'] ?? null)
            || $data['iat'] > time() + 30
            || ! is_string($data['tenant_id'] ?? null)
            || ! Str::isUlid($data['tenant_id'])
            || ! (is_string($data['sub'] ?? null) || is_int($data['sub'] ?? null))
            || trim((string) $data['sub']) === ''
            || ! is_array($data['permissions'] ?? null)
            || ! array_key_exists('data_policies', $data)
            || ! is_array($data['data_policies'])
            || array_filter($data['permissions'], fn ($value) => ! is_string($value)) !== []) {
            return null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;

        return is_array($data) ? $data : [];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
