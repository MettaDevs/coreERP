<?php

namespace App\Support;

use App\Models\TenantMembership;
use RuntimeException;

final class AppContextToken
{
    /** @param list<string> $permissions */
    public function issue(
        TenantMembership $membership,
        string $appId,
        array $permissions,
        ?string $legalEntityId,
        ?string $orgUnitId,
        array $dataPolicies = [],
    ): string {
        $key = (string) config('coreerp.app_context_signing_key');
        if (strlen($key) < 32) {
            throw new RuntimeException('Kunci penandatanganan konteks aplikasi belum dikonfigurasi.');
        }

        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $this->encode([
            'iss' => 'coreerp',
            'aud' => $appId,
            'sub' => $membership->user_id,
            'tenant_id' => $membership->tenant_id,
            'legal_entity_id' => $legalEntityId,
            'org_unit_id' => $orgUnitId,
            'data_policies' => $dataPolicies,
            'permissions' => array_values($permissions),
            'iat' => time(),
            'exp' => time() + 300,
        ]);
        $signature = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, $key, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
