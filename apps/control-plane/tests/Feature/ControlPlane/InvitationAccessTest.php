<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\InvitationCode;
use App\Models\Organization;
use App\Models\OrganizationHierarchyVersion;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\DataPolicyAccessResolver;
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

    public function test_invitation_rejects_direct_and_descendant_grants_for_the_same_unit(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy(
            'management-aset.operating-unit-responsibility',
            'management-aset.entitas-aset.read',
            requiresOperatingUnit: true,
            allowsDescendants: true,
        );
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'name' => 'PT Scope', 'company_code' => 'SCOPE', 'country_code' => 'ID',
        ]);
        $unit = $this->createOrganization([
            'classification' => 'operating_unit', 'name' => 'Unit Scope', 'operating_unit_type' => 'department',
        ]);

        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Scope structure', 'purpose_codes' => ['policy'],
            'root_organization_id' => $legalEntity->id, 'effective_from' => now()->toDateString(),
        ])->assertRedirect();
        $version = OrganizationHierarchyVersion::query()->firstOrFail();
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", [
            'organization_id' => $unit->id, 'parent_organization_id' => $legalEntity->id,
        ])->assertRedirect();
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/publish")->assertRedirect();

        $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'system_role' => 'user',
            'assignments' => [[
                'role_id' => $role->id,
                'policy_scopes' => [
                    [
                        'policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => $unit->id,
                        'hierarchy_id' => null, 'include_descendants' => false,
                    ],
                    [
                        'policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => $unit->id,
                        'hierarchy_id' => $version->hierarchy_id, 'include_descendants' => true,
                    ],
                ],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignments');
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

    public function test_issued_invitation_can_be_edited_without_changing_the_code_or_existing_members(): void
    {
        $first = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $second = $this->createRole('Asset auditor', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');
        $scope = [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ];

        $created = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'system_role' => 'user',
            'label' => 'Batch Agustus',
            'assignments' => [['role_id' => $first->id, 'policy_scopes' => [$scope]]],
        ])->assertCreated();
        $invitationId = $created->json('data.id');
        $code = $created->json('data.code');

        // Satu orang menukarkan kode sebelum undangan diubah.
        auth()->logout();
        $this->postJson('/api/v1/invitation-redemptions', [
            'code' => $code,
            'name' => 'Joined Early',
            'email' => 'early@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();
        $joined = User::query()->where('email', 'early@metta.test')->firstOrFail()->activeMembership();
        $this->assertSame([$first->id], $joined->roleAssignments()->pluck('role_id')->all());

        $this->actingAs($this->owner)->patchJson("/api/v1/invitation-codes/{$invitationId}", [
            'system_role' => 'admin',
            'label' => 'Batch Agustus (revisi)',
            'assignments' => [['role_id' => $second->id, 'policy_scopes' => [$scope]]],
        ])->assertOk();

        $invitation = InvitationCode::findOrFail($invitationId);
        $this->assertSame($code, $invitation->accessibleCode());
        $this->assertSame('admin', $invitation->system_role);
        $this->assertSame([$second->id], $invitation->roles()->pluck('roles.id')->all());
        $this->assertSame(1, DB::table('invitation_data_policy_scopes')
            ->where('invitation_id', $invitationId)->where('role_id', $second->id)->count());
        $this->assertDatabaseHas('access_audit_events', [
            'tenant_id' => $invitation->tenant_id,
            'action' => 'access.invitation.updated',
        ]);

        // Anggota yang sudah bergabung tidak ikut berubah.
        $this->assertSame([$first->id], $joined->roleAssignments()->pluck('role_id')->all());

        // Penukar berikutnya menerima role yang baru.
        auth()->logout();
        $this->postJson('/api/v1/invitation-redemptions', [
            'code' => $code,
            'name' => 'Joined Later',
            'email' => 'later@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();
        $later = User::query()->where('email', 'later@metta.test')->firstOrFail()->activeMembership();
        $this->assertSame([$second->id], $later->roleAssignments()->pluck('role_id')->all());
        $this->assertSame('admin', $later->system_role);
    }

    public function test_revoked_invitation_cannot_be_edited(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');
        $scope = [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ];
        $invitationId = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'system_role' => 'user',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertCreated()->json('data.id');
        InvitationCode::findOrFail($invitationId)->update(['revoked_at' => now()]);

        $this->actingAs($this->owner)->patchJson("/api/v1/invitation-codes/{$invitationId}", [
            'system_role' => 'admin',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertUnprocessable();
    }

    public function test_member_without_access_management_cannot_edit_an_invitation(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy('management-aset.entitas-responsibility', 'management-aset.entitas-aset.read');
        $scope = [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ];
        $invitationId = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'system_role' => 'user',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertCreated()->json('data.id');

        $plain = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'user_id' => $plain->id,
            'system_role' => 'user',
            'status' => 'active',
        ]);

        $this->actingAs($plain)->patchJson("/api/v1/invitation-codes/{$invitationId}", [
            'system_role' => 'admin',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertForbidden();
    }

    public function test_grant_without_dimensions_is_rejected_unless_it_is_declared_unrestricted(): void
    {
        $role = $this->createRole('Asset administrator', ['management-aset.entitas-aset.manage']);
        $policyCode = $this->createPolicy(
            'management-aset.operating-unit-responsibility',
            'management-aset.entitas-aset.read',
            requiresOperatingUnit: true,
        );
        $membership = $this->owner->activeMembership();
        $scope = [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ];

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'system_role' => 'owner',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'system_role' => 'owner',
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [[...$scope, 'unrestricted' => true]]]],
        ])->assertOk();

        $this->assertDatabaseHas('role_assignment_data_policy_scopes', [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
        ]);
        // Pemeriksaan ini membaca keadaan **setelah** permintaan di atas menuliskannya, jadi ia
        // harus melihat container yang bersih — sama seperti permintaan berikutnya di produksi.
        // Resolver mengingat jawabannya selama satu permintaan; tanpa baris ini yang terbaca
        // adalah ingatan dari permintaan yang baru saja melakukan penulisannya.
        $this->app->forgetScopedInstances();

        $this->assertTrue(
            app(DataPolicyAccessResolver::class)->resolve($membership->fresh())[$policyCode]['all'],
        );
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

    private function createOrganization(array $data): Organization
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/organizations', $data)->assertCreated()->json('data.id');

        return Organization::query()->findOrFail($id);
    }

    private function createPolicy(
        string $code,
        string $permission,
        bool $requiresOperatingUnit = false,
        bool $allowsDescendants = false,
    ): string {
        DB::table('app_data_policies')->insert([
            'code' => $code, 'app_id' => 'management-aset', 'name' => 'Policy test',
            'protected_permissions' => json_encode([$permission], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => false, 'requires_operating_unit' => $requiresOperatingUnit,
            'allows_descendants' => $allowsDescendants, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
