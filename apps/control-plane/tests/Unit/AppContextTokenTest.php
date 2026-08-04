<?php

namespace Tests\Unit;

use App\Models\TenantMembership;
use App\Support\AppContextToken;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppContextTokenTest extends TestCase
{
    public function test_it_signs_the_active_tenant_and_effective_permissions_for_one_app(): void
    {
        config(['coreerp.app_context_signing_key' => 'test-context-signing-key-32-bytes']);
        $membership = new TenantMembership([
            'tenant_id' => (string) Str::ulid(),
            'user_id' => (string) Str::ulid(),
            'system_role' => 'user',
            'status' => 'active',
        ]);

        $token = app(AppContextToken::class)->issue(
            $membership,
            'management-aset',
            ['management-aset.entitas-aset.read'],
            null,
            null,
        );
        [$header, $payload, $signature] = explode('.', $token);
        $expected = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            $header.'.'.$payload,
            'test-context-signing-key-32-bytes',
            true,
        )), '+/', '-_'), '=');
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expected, $signature);
        $this->assertSame('management-aset', $claims['aud']);
        $this->assertSame($membership->tenant_id, $claims['tenant_id']);
        $this->assertSame(['management-aset.entitas-aset.read'], $claims['permissions']);
        $this->assertGreaterThan(time(), $claims['exp']);
    }
}
