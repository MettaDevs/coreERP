<?php

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_select_legal_entity_and_operating_unit_with_an_effective_policy_scope(): void
    {
        $this->seed(AppCatalogSeeder::class);
        DB::table('app_data_policies')->insert([
            'code' => 'app-uji.tanggung-jawab',
            'app_id' => 'app-uji',
            'name' => 'Akses aset menurut unit penanggung jawab',
            'protected_permissions' => json_encode(['app-uji.entitas.read'], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => true,
            'requires_operating_unit' => true,
            'allows_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        [$membership, $legalEntity, $unit] = $this->createWorkspace($user, 'alpha', 'Alpha Outlet');
        $this->createWorkspace($user, 'beta', 'Beta Outlet');
        $assignment = RoleAssignment::create([
            'membership_id' => $membership->id,
            'role_id' => Role::create(['tenant_id' => $membership->tenant_id, 'name' => 'Workspace viewer'])->id,
            'source' => 'manual',
            'status' => 'active',
            'valid_from' => now(),
        ]);
        $assignment->dataPolicyScopes()->create([
            'tenant_id' => $membership->tenant_id,
            'policy_code' => 'app-uji.tanggung-jawab',
            'include_descendants' => false,
            'valid_from' => now(),
        ]);

        $this->actingAs($user)->putJson('/api/v1/workspace-context', [
            'membership_id' => $membership->id,
            'legal_entity_id' => $legalEntity->id,
            'org_unit_id' => $unit->id,
        ])->assertOk()
            ->assertJsonPath('data.legal_entity_id', $legalEntity->id)
            ->assertJsonPath('data.org_unit_id', $unit->id);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('auth.membership.id', $membership->id)
            ->where('workspace.active_legal_entity.id', $legalEntity->id)
            ->where('workspace.active_org_unit.id', $unit->id)
            ->has('workspace.memberships', 2));
    }

    public function test_user_cannot_switch_to_another_users_membership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$otherMembership, $legalEntity, $unit] = $this->createWorkspace($other, 'other', 'Other Outlet');

        $this->actingAs($user)->putJson('/api/v1/workspace-context', [
            'membership_id' => $otherMembership->id,
            'legal_entity_id' => $legalEntity->id,
            'org_unit_id' => $unit->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_id');
    }

    public function test_regular_user_cannot_select_organization_without_assignment_scope(): void
    {
        $user = User::factory()->create();
        [$membership, $legalEntity, $unit] = $this->createWorkspace($user, 'restricted', 'Restricted Outlet', 'user');

        $this->actingAs($user)->putJson('/api/v1/workspace-context', [
            'membership_id' => $membership->id,
            'legal_entity_id' => $legalEntity->id,
            'org_unit_id' => $unit->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
    }

    /** @return array{TenantMembership, Organization, Organization} */
    private function createWorkspace(User $user, string $slug, string $unitName, string $systemRole = 'owner'): array
    {
        $client = Client::create(['legal_name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active']);
        $tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'active',
        ]);
        $legalEntity = Organization::create([
            'tenant_id' => $tenant->id,
            'name' => ucfirst($slug).' Legal Entity',
            'classification' => 'legal_entity',
            'status' => 'active',
        ]);
        $legalEntity->legalEntity()->create([
            'tenant_id' => $tenant->id,
            'company_code' => strtoupper($slug),
            'country_code' => 'ID',
        ]);
        $unit = Organization::create([
            'tenant_id' => $tenant->id,
            'name' => $unitName,
            'classification' => 'operating_unit',
            'status' => 'active',
        ]);
        $unit->operatingUnit()->create(['type' => 'department']);
        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'system_role' => $systemRole,
            'status' => 'active',
        ]);

        return [$membership, $legalEntity, $unit];
    }
}
