<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\InvitationCode;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvitationAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner',
            'business_name' => 'PT Metta',
            'app_ids' => ['management-aset'],
            'email' => 'owner@metta.test',
            'password' => 'password',
        ]);
    }

    public function test_invitation_code_can_be_reused_and_assigns_role_and_policy_scope(): void
    {
        $role = $this->createRole('Finance and Asset Controller', [
            'management-aset.entitas-aset.manage',
        ]);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'system_role' => 'admin',
            'assignments' => [[
                'role_id' => $role->id,
                'policy_scopes' => [[
                    'policy_code' => $policyCode,
                    'legal_entity_id' => null,
                    'organization_id' => null,
                    'hierarchy_id' => null,
                    'include_descendants' => false,
                ]],
            ]],
        ])->assertCreated();
        $code = $response->json('data.code');
        $this->assertDatabaseMissing('invitation_codes', ['code_hash' => $code]);
        $this->assertDatabaseMissing('invitation_codes', ['code_ciphertext' => $code]);
        $this->assertSame($code, InvitationCode::findOrFail($response->json('data.id'))->accessibleCode());

        auth()->logout();
        $payload = [
            'code' => $code,
            'name' => 'Second User',
            'email' => 'second@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
        $this->postJson('/api/v1/invitation-redemptions', $payload)->assertCreated();
        $this->assertDatabaseHas('role_assignments', ['role_id' => $role->id, 'source' => 'manual', 'status' => 'active']);
        $this->assertDatabaseHas('role_assignment_data_policy_scopes', [
            'policy_code' => $policyCode,
            'organization_id' => null,
        ]);

        $payload['email'] = 'third@metta.test';
        $payload['name'] = 'Third User';
        auth()->logout();
        $this->postJson('/api/v1/invitation-redemptions', $payload)->assertCreated();
        $this->assertDatabaseCount('tenant_memberships', 3);
        $this->assertDatabaseCount('role_assignments', 3);
        $this->assertDatabaseCount('role_assignment_data_policy_scopes', 2);
    }

    public function test_expired_and_revoked_codes_are_rejected(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');
        foreach (['expired', 'revoked'] as $state) {
            $response = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
                'system_role' => 'user',
                'assignments' => [[
                    'role_id' => $role->id,
                    'policy_scopes' => [[
                        'policy_code' => $policyCode,
                        'legal_entity_id' => null,
                        'organization_id' => null,
                        'hierarchy_id' => null,
                        'include_descendants' => false,
                    ]],
                ]],
            ])->assertCreated();
            $invitation = InvitationCode::findOrFail($response->json('data.id'));
            $invitation->update($state === 'expired' ? ['expires_at' => now()->subMinute()] : ['revoked_at' => now()]);

            auth()->logout();
            $this->postJson('/api/v1/invitation-redemptions', [
                'code' => $response->json('data.code'),
                'name' => 'Blocked',
                'email' => "{$state}@metta.test",
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertUnprocessable();
        }
    }

    public function test_role_can_receive_the_entitas_aset_duty(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');

        $this->assertSame(1, DB::table('security_role_duties')->where('role_id', $role->id)->count());
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Asset administrator']);
    }

    public function test_owner_can_assign_a_business_role_to_their_own_membership(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');
        $membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'system_role' => 'owner',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [[
                'policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => null,
                'hierarchy_id' => null, 'include_descendants' => false,
            ]]]],
        ])->assertOk();

        $this->assertDatabaseHas('tenant_memberships', ['id' => $membership->id, 'system_role' => 'owner']);
        $this->assertDatabaseHas('role_assignments', [
            'membership_id' => $membership->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    public function test_member_cannot_receive_duplicate_tenant_wide_grants_for_one_role(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');
        $membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'system_role' => 'owner',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [
                ['policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => null, 'hierarchy_id' => null, 'include_descendants' => false],
                ['policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => null, 'hierarchy_id' => null, 'include_descendants' => false],
            ]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignments');
    }

    public function test_policy_that_requires_an_operating_unit_rejects_a_legal_entity_node(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy(
            'management-aset.operating-unit-responsibility',
            'management-aset.entitas-aset.read',
            requiresOperatingUnit: true,
        );
        $legalEntity = $this->createLegalEntity();
        $membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'system_role' => 'owner',
            'assignments' => [[
                'role_id' => $role->id,
                'policy_scopes' => [[
                    'policy_code' => $policyCode,
                    'legal_entity_id' => null,
                    'organization_id' => $legalEntity->id,
                    'hierarchy_id' => null,
                    'include_descendants' => false,
                ]],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
    }

    public function test_admin_cannot_change_the_owner_access(): void
    {
        $ownerMembership = $this->owner->activeMembership();
        $admin = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $ownerMembership->tenant_id,
            'user_id' => $admin->id,
            'system_role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)->patchJson("/api/v1/memberships/{$ownerMembership->id}", [
            'system_role' => 'owner',
            'assignments' => [],
        ])->assertForbidden();
    }

    private function createRole(string $name, array $duties): Role
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/roles', [
            'name' => $name,
            'duty_codes' => $duties,
        ])->assertCreated()->json('data.id');

        return Role::findOrFail($id);
    }

    private function createLegalEntity(): Organization
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/organizations', [
            'classification' => 'legal_entity',
            'name' => 'PT Metta',
            'company_code' => 'META',
            'country_code' => 'ID',
        ])->assertCreated()->json('data.id');

        return Organization::findOrFail($id);
    }

    private function createPolicy(string $code, string $permission, bool $requiresOperatingUnit = false): string
    {
        DB::table('app_data_policies')->insert([
            'code' => $code, 'app_id' => 'management-aset', 'name' => 'Policy test',
            'protected_permissions' => json_encode([$permission], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => false, 'requires_operating_unit' => $requiresOperatingUnit,
            'allows_descendants' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
