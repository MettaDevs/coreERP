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
            'app_ids' => ['procurement', 'management-asset'],
            'email' => 'owner@metta.test',
            'password' => 'password',
        ]);
    }

    public function test_invite_is_single_use_and_assigns_responsibility_role_with_organization_scope(): void
    {
        $role = $this->createRole('Finance and Asset Controller', [
            'procurement.requisition.approve',
            'management-asset.asset.retire',
        ]);
        $organization = $this->createLegalEntity();

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'system_role' => 'admin',
            'role_ids' => [$role->id],
            'organization_id' => $organization->id,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ])->assertCreated();
        $code = $response->json('data.code');
        $this->assertDatabaseMissing('invitation_codes', ['code_hash' => $code]);

        auth()->logout();
        $payload = [
            'code' => $code,
            'name' => 'Second User',
            'email' => 'second@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
        $this->postJson('/api/v1/invitation-redemptions', $payload)->assertCreated();
        $assignmentId = DB::table('role_assignments')->where('role_id', $role->id)->value('id');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'source' => 'manual', 'status' => 'active']);
        $this->assertDatabaseHas('role_assignment_org_scopes', [
            'assignment_id' => $assignmentId,
            'organization_id' => $organization->id,
            'include_descendants' => false,
        ]);

        $payload['email'] = 'third@metta.test';
        auth()->logout();
        $this->postJson('/api/v1/invitation-redemptions', $payload)->assertUnprocessable();
    }

    public function test_expired_and_revoked_codes_are_rejected(): void
    {
        $role = $this->createRole('Buyer', ['procurement.requisition.maintain']);
        foreach (['expired', 'revoked'] as $state) {
            $response = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
                'system_role' => 'user',
                'role_ids' => [$role->id],
                'organization_id' => null,
                'hierarchy_id' => null,
                'include_descendants' => false,
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

    public function test_role_can_combine_duties_from_multiple_entitled_products(): void
    {
        $role = $this->createRole('Multi-job manager', [
            'procurement.requisition.approve',
            'management-asset.asset.retire',
        ]);

        $this->assertSame(2, DB::table('security_role_duties')->where('role_id', $role->id)->count());
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Multi-job manager']);
    }

    public function test_owner_can_assign_a_business_role_to_their_own_membership(): void
    {
        $role = $this->createRole('Buyer', ['procurement.requisition.maintain']);
        $membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'system_role' => 'owner',
            'role_ids' => [$role->id],
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ])->assertOk();

        $this->assertDatabaseHas('tenant_memberships', ['id' => $membership->id, 'system_role' => 'owner']);
        $this->assertDatabaseHas('role_assignments', [
            'membership_id' => $membership->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
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
            'role_ids' => [],
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
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
            'code' => 'METTA',
            'name' => 'PT Metta',
            'company_code' => 'META',
            'country_code' => 'ID',
        ])->assertCreated()->json('data.id');

        return Organization::findOrFail($id);
    }
}
