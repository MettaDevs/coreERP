<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Tenant;

use App\Platform\ControlPlane\Models\ProductCustomer;
use App\Platform\ControlPlane\Models\ProductCustomerSession;
use App\Platform\Identity\Models\ExternalIdentity;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Models\ProviderAccess;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CanonicalTenantCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_principal_can_create_two_tenants_without_bootstrapping_erp(): void
    {
        $principal = User::factory()->create();
        $customer = $this->customer($principal->email);
        ExternalIdentity::create([
            'user_id' => $principal->id,
            'issuer' => $customer->issuer,
            'subject' => $customer->subject,
            'email_at_link' => $customer->email,
        ]);
        $headers = $this->customerHeaders($customer);
        $tenantIds = [];

        foreach (range(1, 2) as $index) {
            $response = $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', [
                'name' => fake()->company(),
                'slug' => 'organization-'.Str::lower((string) Str::ulid()),
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $response->assertCreated()->assertJsonPath('principal_id', $principal->id);
            $tenantIds[] = $response->json('tenant_id');
        }

        $this->assertNotSame($tenantIds[0], $tenantIds[1]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('tenant_memberships', 2);
        $this->assertSame($principal->id, $customer->fresh()->user_id);

        foreach (['roles', 'role_assignments', 'tenant_app_entitlements', 'environments', 'environment_members', 'organizations', 'core_module_installations', 'units_of_measure', 'tenant_number_sequences', 'outbox_events', 'provider_access'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must remain empty for tenant-only creation');
        }
    }

    public function test_a_new_subject_is_bound_only_when_it_creates_a_tenant_and_reuses_that_principal(): void
    {
        $customer = $this->customer(fake()->unique()->safeEmail());
        $headers = $this->customerHeaders($customer);
        $this->assertDatabaseCount('users', 0);
        $first = $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertCreated();

        $this->assertSame($first->json('principal_id'), $second->json('principal_id'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('external_identities', 1);
        $this->assertDatabaseCount('provider_access', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_distinct_subjects_with_the_same_provider_email_are_not_merged(): void
    {
        $providerEmail = fake()->unique()->safeEmail();
        $first = $this->customer(fake()->unique()->safeEmail());
        $second = $this->customer(fake()->unique()->safeEmail());
        $first->update(['email' => $providerEmail]);
        $second->update(['email' => $providerEmail]);
        $a = $this->withHeaders($this->customerHeaders($first))->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertCreated();
        $b = $this->withHeaders($this->customerHeaders($second))->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertCreated();

        $this->assertNotSame($a->json('principal_id'), $b->json('principal_id'));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('external_identities', 2);
    }

    public function test_email_collision_without_an_exact_subject_link_does_not_claim_an_existing_user(): void
    {
        $existing = User::factory()->create();
        $customer = $this->customer($existing->email);
        $this->withHeaders($this->customerHeaders($customer))->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertConflict();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('external_identities', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertNull($customer->fresh()->user_id);
    }

    public function test_request_replay_returns_the_same_tenant_but_changed_payload_is_rejected(): void
    {
        $customer = $this->customer(fake()->unique()->safeEmail());
        $headers = $this->customerHeaders($customer);
        $payload = $this->payload();
        $first = $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $payload)->assertCreated();
        $replay = $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($first->json('tenant_id'), $replay->json('tenant_id'));
        $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', [...$payload, 'name' => $payload['name'].' changed'])->assertConflict();
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('tenant_creation_requests', 1);
        $this->assertSame(1, DB::table('operator_audit_events')->where('action', 'tenant.created')->count());
    }

    public function test_replay_does_not_reactivate_a_closed_tenant_or_membership(): void
    {
        $customer = $this->customer(fake()->unique()->safeEmail());
        $headers = $this->customerHeaders($customer);
        $payload = $this->payload();
        $first = $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $payload)->assertCreated();
        TenantMembership::query()->whereKey($first->json('membership_id'))->update(['status' => 'inactive']);
        $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $payload)->assertConflict();
        $this->assertSame('inactive', TenantMembership::query()->findOrFail($first->json('membership_id'))->status);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_a_conflicting_slug_rolls_back_a_new_principal_and_binding(): void
    {
        $a = $this->customer(fake()->unique()->safeEmail());
        $b = $this->customer(fake()->unique()->safeEmail());
        $payload = $this->payload();
        $this->withHeaders($this->customerHeaders($a))->postJson('/api/internal/v1/product-registration/organizations', $payload)->assertCreated();
        $this->withHeaders($this->customerHeaders($b))->postJson('/api/internal/v1/product-registration/organizations', [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($b->fresh()->user_id);
    }

    public function test_customer_cannot_supply_another_actor_owner_or_erp_bootstrap_fields(): void
    {
        $customer = $this->customer(fake()->unique()->safeEmail());
        $this->withHeaders($this->customerHeaders($customer))->postJson('/api/internal/v1/product-registration/organizations', [
            ...$this->payload(), 'requested_by' => 100, 'owner_user_id' => 100, 'app_ids' => ['core'], 'first_environment' => 'production',
        ])->assertUnprocessable()->assertJsonValidationErrors(['requested_by', 'owner_user_id', 'app_ids', 'first_environment']);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_customer_session_is_required_and_cannot_open_the_operator_endpoint(): void
    {
        $customer = $this->customer(fake()->unique()->safeEmail());
        $headers = $this->customerHeaders($customer);
        $this->withToken(substr($headers['Authorization'], 7))->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertUnauthorized();
        config(['coreerp.control_plane_token' => Str::random(48)]);
        $this->withHeaders($headers)->postJson('/api/internal/v1/tenant-registrations', [...$this->payload(), 'requested_by' => 1, 'owner_user_id' => 1])->assertUnauthorized();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_operator_actor_is_audited_and_does_not_become_the_owner(): void
    {
        $operator = User::factory()->create();
        $owner = User::factory()->create();
        ProviderAccess::create(['user_id' => $operator->id, 'role' => 'provider_admin']);
        $token = Str::random(48);
        config(['coreerp.control_plane_token' => $token]);
        $payload = [...$this->payload(), 'requested_by' => $operator->id, 'owner_user_id' => $owner->id];
        $created = $this->withToken($token)->postJson('/api/internal/v1/tenant-registrations', $payload)->assertCreated()->assertJsonPath('principal_id', $owner->id);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $created->json('tenant_id'), 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('tenant_memberships', ['tenant_id' => $created->json('tenant_id'), 'user_id' => $operator->id]);
        $this->assertDatabaseHas('operator_audit_events', ['action' => 'tenant.created', 'subject_id' => $created->json('tenant_id'), 'user_id' => $operator->id]);
        ProviderAccess::query()->whereKey($operator->id)->update(['role' => 'revoked']);
        $this->withToken($token)->postJson('/api/internal/v1/tenant-registrations', $payload)->assertNotFound();
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_an_archived_identity_or_mismatched_binding_is_not_reactivated(): void
    {
        $principal = User::factory()->create();
        $customer = $this->customer($principal->email);
        $identity = ExternalIdentity::create(['user_id' => $principal->id, 'issuer' => $customer->issuer, 'subject' => $customer->subject, 'email_at_link' => $principal->email]);
        $identity->delete();
        $this->withHeaders($this->customerHeaders($customer))->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertConflict();
        $this->assertTrue($identity->fresh()->trashed());
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_a_binding_to_another_principal_is_rejected(): void
    {
        $principal = User::factory()->create();
        $other = User::factory()->create();
        $customer = $this->customer($principal->email);
        $customer->forceFill(['user_id' => $other->id])->save();
        ExternalIdentity::create(['user_id' => $principal->id, 'issuer' => $customer->issuer, 'subject' => $customer->subject, 'email_at_link' => $principal->email]);
        $this->withHeaders($this->customerHeaders($customer))->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertConflict();
        $this->assertSame($other->id, $customer->fresh()->user_id);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_an_explicitly_relinked_active_identity_takes_precedence_over_its_history(): void
    {
        $previous = User::factory()->create();
        $current = User::factory()->create();
        $customer = $this->customer($current->email);
        $old = ExternalIdentity::create(['user_id' => $previous->id, 'issuer' => $customer->issuer, 'subject' => $customer->subject]);
        $old->delete();
        ExternalIdentity::create(['user_id' => $current->id, 'issuer' => $customer->issuer, 'subject' => $customer->subject]);
        $this->withHeaders($this->customerHeaders($customer))->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertCreated()->assertJsonPath('principal_id', $current->id);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_customer_without_verified_contact_and_application_principal_are_rejected(): void
    {
        $principal = User::factory()->create();
        $principal->forceFill(['account_type' => User::APPLICATION])->save();
        $customer = $this->customer($principal->email);
        ExternalIdentity::create(['user_id' => $principal->id, 'issuer' => $customer->issuer, 'subject' => $customer->subject]);
        $headers = $this->customerHeaders($customer);
        $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertConflict();
        $customer->update(['email_verified_at' => null]);
        $this->withHeaders($headers)->postJson('/api/internal/v1/product-registration/organizations', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 0);
        $this->assertNull($customer->fresh()->user_id);
    }

    public function test_a_nonoperator_cannot_choose_an_operator_actor_or_create_for_another_owner(): void
    {
        $ordinary = User::factory()->create();
        $owner = User::factory()->create();
        $token = Str::random(48);
        config(['coreerp.control_plane_token' => $token]);
        $this->withToken($token)->postJson('/api/internal/v1/tenant-registrations', [...$this->payload(), 'requested_by' => $ordinary->id, 'owner_user_id' => $owner->id])->assertNotFound();
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('tenant_creation_requests', 0);
    }

    /** @return array{name:string,slug:string,idempotency_key:string} */
    private function payload(): array
    {
        return ['name' => fake()->company(), 'slug' => 'organization-'.Str::lower(Str::random(16)), 'idempotency_key' => (string) Str::uuid()];
    }

    private function customer(string $email): ProductCustomer
    {
        $issuer = 'https://identity.example.test';
        $subject = (string) Str::uuid();

        return ProductCustomer::create([
            'id' => (string) Str::uuid(),
            'identity_hash' => hash('sha256', json_encode([$issuer, $subject], JSON_THROW_ON_ERROR)),
            'issuer' => $issuer,
            'subject' => $subject,
            'name' => fake()->name(),
            'email' => $email,
            'verified_email' => $email,
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function customerHeaders(ProductCustomer $customer): array
    {
        $gateway = Str::random(48);
        $session = Str::random(64);
        config(['managed_products.registration.gateway_token' => $gateway]);
        ProductCustomerSession::create([
            'token_hash' => hash('sha256', $session),
            'customer_id' => $customer->id,
            'expires_at' => now()->addHour(),
        ]);

        return ['Authorization' => 'Bearer '.$gateway, 'X-Registration-Session' => $session];
    }
}
