<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HumanResourcesScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_position_list_only_returns_operating_units_from_signed_scope(): void
    {
        config([
            'services.coreerp.app_id' => 'human-resources',
            'services.coreerp.context_signing_key' => 'human-resources-test-context-key-32',
        ]);

        $tenantId = (string) Str::ulid();
        $allowedUnit = (string) Str::ulid();
        $blockedUnit = (string) Str::ulid();
        $now = now();

        foreach ([[$allowedUnit, 'Posisi yang boleh dilihat'], [$blockedUnit, 'Posisi kantor lain']] as [$unitId, $name]) {
            \DB::table('hr_positions')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'creation_key' => 'position-'.Str::ulid(),
                'code' => 'POSH'.Str::random(5),
                'name' => $name,
                'job_id' => (string) Str::ulid(),
                'operating_unit_id' => $unitId,
                'valid_from' => '2026-01-01',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($tenantId, $allowedUnit))
            ->getJson('/api/v1/positions')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $response);
        $this->assertSame('Posisi yang boleh dilihat', $response[0]['name']);
    }

    private function token(string $tenantId, string $operatingUnitId): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode([
            'iss' => 'coreerp',
            'aud' => 'human-resources',
            'sub' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'permissions' => ['human-resources.positions.read'],
            'data_policies' => [
                'human-resources.workforce-responsibility' => [
                    'all' => false, 'scope_grants' => [['legal_entity_id' => null, 'operating_unit_ids' => [$operatingUnitId]]],
                ],
            ],
            'iat' => time(),
            'exp' => time() + 300,
        ]);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, 'human-resources-test-context-key-32', true)), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }
}
