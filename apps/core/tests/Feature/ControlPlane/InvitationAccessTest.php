<?php

namespace Tests\Feature\ControlPlane;

use App\Models\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Organization\Models\Organization;
use App\Platform\Organization\Models\OrganizationHierarchyVersion;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\InvitationCode;
use App\Platform\Tenant\Models\TenantMembership;
use App\Support\Access\AccessGuards;
use App\Support\Access\CoreSecurityCatalog;
use App\Support\Access\TenantProducts;
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
            'app_ids' => ['app-uji'],
            'email' => 'owner@metta.test',
            'password' => 'password',
        ]);
    }

    public function test_invitation_code_can_be_reused_and_assigns_role_and_policy_scope(): void
    {
        $role = $this->createRole('Finance and Asset Controller', [
            'app-uji.entitas.manage',
        ]);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');

        $response = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
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
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');
        foreach (['expired', 'revoked'] as $state) {
            $response = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
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
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy(
            'app-uji.tanggung-jawab-unit',
            'app-uji.entitas.read',
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
            'version' => $version->hierarchy()->value('version'),
            'organization_id' => $unit->id, 'parent_organization_id' => $legalEntity->id,
        ])->assertRedirect();
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/publish", ['version' => $version->hierarchy()->value('version')])->assertRedirect();

        $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
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
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');

        $this->assertSame(1, DB::table('security_role_duties')->where('role_id', $role->id)->count());
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Asset administrator']);
    }

    public function test_owner_can_assign_a_business_role_to_their_own_membership(): void
    {
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');
        $membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'version' => $membership->fresh()->version,
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [[
                'policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => null,
                'hierarchy_id' => null, 'include_descendants' => false,
            ]]]],
        ])->assertOk();

        // Penugasan manual tidak menyentuh role Owner yang diberikan saat bisnis didaftarkan.
        $this->assertTrue(AccessGuards::holdsOwnerRole($membership->fresh()));
        $this->assertDatabaseHas('role_assignments', [
            'membership_id' => $membership->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    public function test_member_cannot_receive_duplicate_tenant_wide_grants_for_one_role(): void
    {
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');
        $membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'version' => $membership->fresh()->version,
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [
                ['policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => null, 'hierarchy_id' => null, 'include_descendants' => false],
                ['policy_code' => $policyCode, 'legal_entity_id' => null, 'organization_id' => null, 'hierarchy_id' => null, 'include_descendants' => false],
            ]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignments');
    }

    public function test_policy_that_requires_an_operating_unit_rejects_a_legal_entity_node(): void
    {
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy(
            'app-uji.tanggung-jawab-unit',
            'app-uji.entitas.read',
            requiresOperatingUnit: true,
        );
        $legalEntity = $this->createLegalEntity();
        $membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'version' => $membership->fresh()->version,
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
        $first = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $second = $this->createRole('Asset auditor', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');
        $scope = [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ];

        $created = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
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
            'version' => InvitationCode::query()->whereKey($invitationId)->value('version'),
            'label' => 'Batch Agustus (revisi)',
            'assignments' => [['role_id' => $second->id, 'policy_scopes' => [$scope]]],
        ])->assertOk();

        $invitation = InvitationCode::findOrFail($invitationId);
        $this->assertSame($code, $invitation->accessibleCode());
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
    }

    public function test_revoked_invitation_cannot_be_edited(): void
    {
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');
        $scope = [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ];
        $invitationId = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertCreated()->json('data.id');
        InvitationCode::findOrFail($invitationId)->update(['revoked_at' => now()]);

        $this->actingAs($this->owner)->patchJson("/api/v1/invitation-codes/{$invitationId}", [
            'version' => InvitationCode::query()->whereKey($invitationId)->value('version'),
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertUnprocessable();
    }

    public function test_member_without_access_management_cannot_edit_an_invitation(): void
    {
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy('app-uji.tanggung-jawab-entitas', 'app-uji.entitas.read');
        $scope = [
            'policy_code' => $policyCode,
            'legal_entity_id' => null,
            'organization_id' => null,
            'hierarchy_id' => null,
            'include_descendants' => false,
        ];
        $invitationId = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertCreated()->json('data.id');

        $plain = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'user_id' => $plain->id,
            'status' => 'active',
        ]);

        $this->actingAs($plain)->patchJson("/api/v1/invitation-codes/{$invitationId}", [
            'version' => InvitationCode::query()->whereKey($invitationId)->value('version'),
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertForbidden();
    }

    public function test_grant_without_dimensions_is_rejected_unless_it_is_declared_unrestricted(): void
    {
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $policyCode = $this->createPolicy(
            'app-uji.tanggung-jawab-unit',
            'app-uji.entitas.read',
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
            'version' => $membership->fresh()->version,
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => [$scope]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'version' => $membership->fresh()->version,
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

    public function test_access_manager_without_the_owner_role_cannot_grant_or_revoke_it(): void
    {
        $ownerMembership = $this->owner->activeMembership();
        $ownerRole = $this->ownerRole();
        [$manager] = $this->accessManager();

        // Memberi dirinya sendiri role Owner.
        $managerMembership = $manager->activeMembership();
        $this->actingAs($manager)->patchJson("/api/v1/memberships/{$managerMembership->id}", [
            'version' => $managerMembership->fresh()->version,
            'assignments' => [
                ['role_id' => $managerMembership->roleAssignments()->value('role_id'), 'policy_scopes' => []],
                ['role_id' => $ownerRole->id, 'policy_scopes' => []],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        // Membuat undangan yang membawa role Owner.
        $this->actingAs($manager)->postJson('/api/v1/invitation-codes', [
            'assignments' => [['role_id' => $ownerRole->id, 'policy_scopes' => []]],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        // Owner boleh memberikannya; pemegang Kelola akses tetap tidak boleh mencabutnya.
        $colleague = User::factory()->create();
        $colleagueMembership = TenantMembership::create(['tenant_id' => $ownerMembership->tenant_id, 'user_id' => $colleague->id, 'status' => 'active']);
        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$colleagueMembership->id}", [
            'version' => $colleagueMembership->fresh()->version,
            'assignments' => [['role_id' => $ownerRole->id, 'policy_scopes' => []]],
        ])->assertOk();
        $this->assertTrue(AccessGuards::holdsOwnerRole($colleagueMembership->fresh()));

        $this->actingAs($manager)->patchJson("/api/v1/memberships/{$colleagueMembership->id}", [
            'version' => $colleagueMembership->fresh()->version,
            'assignments' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
        $this->assertTrue(AccessGuards::holdsOwnerRole($colleagueMembership->fresh()));
    }

    public function test_access_manager_can_manage_members_without_touching_the_owner_role(): void
    {
        [$manager] = $this->accessManager();
        $role = $this->createRole('Asset administrator', ['app-uji.entitas.manage']);
        $member = User::factory()->create();
        $memberMembership = TenantMembership::create(['tenant_id' => $this->owner->activeMembership()->tenant_id, 'user_id' => $member->id, 'status' => 'active']);

        $this->actingAs($manager)->patchJson("/api/v1/memberships/{$memberMembership->id}", [
            'version' => $memberMembership->fresh()->version,
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => []]],
        ])->assertOk();

        $this->assertSame([$role->id], $memberMembership->roleAssignments()->pluck('role_id')->all());
    }

    public function test_a_change_that_leaves_nobody_able_to_manage_access_is_rejected(): void
    {
        [$manager, $managerRole] = $this->accessManager();
        // Owner yang tersisa sudah tidak aktif, jadi pemegang Kelola akses ini satu-satunya.
        $this->owner->activeMembership()->roleAssignments()->update(['status' => 'inactive']);
        $membership = $manager->activeMembership();

        $this->actingAs($manager)->patchJson("/api/v1/memberships/{$membership->id}", [
            'version' => $membership->fresh()->version,
            'assignments' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('access');
        $this->actingAs($manager)->deleteJson("/api/v1/roles/{$managerRole->id}", ['version' => $managerRole->fresh()->version])
            ->assertUnprocessable()->assertJsonValidationErrors('access');

        $this->assertSame([$managerRole->id], $membership->roleAssignments()->pluck('role_id')->all());
        $this->assertDatabaseHas('roles', ['id' => $managerRole->id]);
    }

    public function test_the_owner_role_cannot_be_edited_deleted_or_nested(): void
    {
        $ownerRole = $this->ownerRole();

        $this->actingAs($this->owner)->putJson("/api/v1/roles/{$ownerRole->id}", [
            'version' => $ownerRole->fresh()->version,
            'name' => 'Owner',
            'duty_codes' => ['app-uji.entitas.manage'],
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->owner)->deleteJson("/api/v1/roles/{$ownerRole->id}", ['version' => $ownerRole->fresh()->version])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->actingAs($this->owner)->postJson('/api/v1/roles', [
            'name' => 'Super',
            'duty_codes' => ['app-uji.entitas.manage'],
            'child_role_ids' => [$ownerRole->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('child_role_ids');

        $this->assertDatabaseHas('roles', ['id' => $ownerRole->id, 'is_owner' => true]);
        $this->assertSame(
            TenantProducts::duties($ownerRole->tenant_id)->count(),
            DB::table('security_role_duties')->where('role_id', $ownerRole->id)->count(),
        );
    }

    private function ownerRole(): Role
    {
        return Role::query()->where('tenant_id', $this->owner->activeMembership()->tenant_id)->where('is_owner', true)->sole();
    }

    /**
     * Anggota yang memegang *Kelola akses* lewat role biasa, tanpa role Owner.
     *
     * @return array{0: User, 1: Role}
     */
    private function accessManager(): array
    {
        $role = $this->createRole('Pengelola akses', [CoreSecurityCatalog::ACCESS_MANAGE_DUTY]);
        $user = User::factory()->create();
        $membership = TenantMembership::create(['tenant_id' => $this->owner->activeMembership()->tenant_id, 'user_id' => $user->id, 'status' => 'active']);
        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'version' => $membership->fresh()->version,
            'assignments' => [['role_id' => $role->id, 'policy_scopes' => []]],
        ])->assertOk();

        return [$user, $role];
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
            'code' => $code, 'app_id' => 'app-uji', 'name' => 'Policy test',
            'protected_permissions' => json_encode([$permission], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => false, 'requires_operating_unit' => $requiresOperatingUnit,
            'allows_descendants' => $allowsDescendants, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }
}
