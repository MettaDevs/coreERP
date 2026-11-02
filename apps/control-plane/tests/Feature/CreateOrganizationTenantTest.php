<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Customers\CreateOrganizationTenant;
use ControlPlane\Customers\CustomerRejected;
use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class CreateOrganizationTenantTest extends TestCase
{
    use CoreSchema;

    public function test_console_sends_the_authenticated_actor_and_explicit_owner_without_writing_tenant_rows(): void
    {
        Http::preventStrayRequests();
        config(['core.base_url' => 'https://core.example.test', 'core.token' => Str::random(48)]);
        $operator = new User;
        $operator->id = 41;
        $tenantId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        Http::fake(['https://core.example.test/api/internal/v1/tenant-registrations' => Http::response([
            'tenant_id' => $tenantId, 'membership_id' => $membershipId, 'principal_id' => 52, 'replayed' => false,
        ], 201)]);
        $key = (string) Str::uuid();
        $result = app(CreateOrganizationTenant::class)($operator, 52, 'Organization fixture', 'organization-fixture', $key);
        $this->assertSame($tenantId, $result['tenant_id']);
        $this->assertDatabaseCount('tenants', 0);
        Http::assertSent(fn ($request): bool => $request['requested_by'] === 41 && $request['owner_user_id'] === 52 && $request['idempotency_key'] === $key && ! isset($request['app_ids']));
    }

    public function test_console_does_not_accept_a_response_for_another_principal(): void
    {
        Http::preventStrayRequests();
        config(['core.base_url' => 'https://core.example.test', 'core.token' => Str::random(48)]);
        $operator = new User;
        $operator->id = 41;
        Http::fake(['*' => Http::response([
            'tenant_id' => (string) Str::ulid(), 'membership_id' => (string) Str::ulid(), 'principal_id' => 99, 'replayed' => false,
        ], 201)]);
        $this->expectException(CustomerRejected::class);
        app(CreateOrganizationTenant::class)($operator, 52, 'Organization fixture', 'organization-fixture', (string) Str::uuid());
    }
}
