<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;

/**
 * Membuat token konteks yang setara dengan terbitan Web Shell agar test menembus
 * gateway app tanpa pernah mengirim `tenant_id` sebagai input request.
 */
trait InteractsWithCoreErpContext
{
    private string $contextSigningKey = 'test-context-signing-key-32-bytes';

    protected function configureCoreErpContext(): void
    {
        config([
            'services.coreerp.url' => 'http://core.test',
            'services.coreerp.app_id' => 'management-aset',
            'services.coreerp.service_token' => 'service-token',
            'services.coreerp.context_signing_key' => $this->contextSigningKey,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, string>
     */
    protected function contextHeaders(string $tenantId, array $permissions, array $claims = []): array
    {
        return ['Authorization' => 'Bearer '.$this->contextToken($tenantId, $permissions, $claims)];
    }

    /** @param list<string> $permissions */
    protected function contextToken(string $tenantId, array $permissions, array $claims = []): string
    {
        $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payloadData = [
            'iss' => 'coreerp',
            'aud' => 'management-aset',
            'sub' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'permissions' => $permissions,
            'data_policies' => [
                'management-aset.asset-responsibility' => [
                    'all' => true, 'scope_grants' => [],
                ],
            ],
            'iat' => time(),
            'exp' => time() + 300,
        ];
        // `sub` ikut dapat ditentukan karena identitas pengguna dibaca dari sana oleh
        // middleware konteks; fitur yang menyaring per pengguna, seperti daftar pekerjaan
        // milik seorang teknisi, tidak dapat diuji tanpa mengendalikannya.
        foreach (['sub', 'legal_entity_id', 'org_unit_id', 'user_id', 'data_policies'] as $claim) {
            if (array_key_exists($claim, $claims)) {
                $payloadData[$claim] = $claims[$claim];
            }
        }
        $payload = $encode($payloadData);
        $signature = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            $header.'.'.$payload,
            $this->contextSigningKey,
            true,
        )), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }
}
