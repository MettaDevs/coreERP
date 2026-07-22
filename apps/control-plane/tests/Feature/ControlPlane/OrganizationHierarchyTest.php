<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Organization;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuleCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner',
            'business_name' => 'PT Metta',
            'module_ids' => ['procurement'],
            'email' => 'owner@metta.test',
            'password' => 'password',
        ]);
    }

    public function test_owner_builds_directory_then_purpose_scoped_versioned_hierarchy(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity',
            'code' => 'METTA',
            'name' => 'PT Metta',
            'company_code' => 'META',
            'country_code' => 'ID',
        ]);
        $department = $this->createOrganization([
            'classification' => 'operating_unit',
            'code' => 'FIN',
            'name' => 'Finance',
            'operating_unit_type' => 'department',
        ]);

        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Management structure',
            'purpose_codes' => ['management', 'policy'],
            'root_organization_id' => $legalEntity->id,
            'effective_from' => now()->toDateString(),
        ])->assertRedirect();

        $version = OrganizationHierarchyVersion::query()->firstOrFail();
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", [
            'organization_id' => $department->id,
            'parent_organization_id' => $legalEntity->id,
        ])->assertRedirect();
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/publish")->assertRedirect();

        $this->assertDatabaseHas('organization_hierarchy_purposes', ['hierarchy_id' => $version->hierarchy_id]);
        $this->assertDatabaseHas('organization_hierarchy_closures', [
            'version_id' => $version->id,
            'ancestor_organization_id' => $legalEntity->id,
            'descendant_organization_id' => $department->id,
            'distance' => 1,
        ]);
        $this->assertDatabaseHas('organization_hierarchy_versions', ['id' => $version->id, 'status' => 'published']);
    }

    public function test_same_organization_can_be_used_by_multiple_hierarchies_without_duplication(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'code' => 'METTA', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
        ]);
        foreach ([['Management', 'management'], ['Procurement', 'procurement']] as [$name, $purpose]) {
            $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
                'name' => $name,
                'purpose_codes' => [$purpose],
                'root_organization_id' => $legalEntity->id,
                'effective_from' => now()->toDateString(),
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_hierarchies', 2);
        $this->assertDatabaseCount('organization_hierarchy_nodes', 2);
    }

    public function test_duplicate_hierarchy_name_returns_a_validation_error(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'code' => 'METTA', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
        ]);
        $payload = [
            'name' => 'Business policy',
            'purpose_codes' => ['policy'],
            'root_organization_id' => $legalEntity->id,
            'effective_from' => now()->toDateString(),
        ];

        $this->actingAs($this->owner)->postJson('/settings/organization/hierarchies', $payload)->assertRedirect();
        $this->postJson('/settings/organization/hierarchies', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('organization_hierarchies', 1);
    }

    public function test_establishment_is_a_hierarchy_purpose_not_an_operating_unit_type(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/organizations', [
            'classification' => 'operating_unit',
            'code' => 'EST',
            'name' => 'Main establishment',
            'operating_unit_type' => 'establishment',
        ])->assertUnprocessable()->assertJsonValidationErrors('operating_unit_type');

        $this->assertDatabaseHas('hierarchy_purposes', [
            'code' => 'establishment',
            'name' => 'Enterprise establishment structure',
        ]);
    }

    public function test_regular_member_cannot_manage_organization_directory(): void
    {
        $member = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'user_id' => $member->id,
            'system_role' => 'user',
            'status' => 'active',
        ]);

        $this->actingAs($member)->postJson('/api/v1/organizations', [
            'classification' => 'operating_unit',
            'code' => 'FIN',
            'name' => 'Finance',
            'operating_unit_type' => 'department',
        ])->assertForbidden();
    }

    private function createOrganization(array $data): Organization
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/organizations', $data)->assertCreated()->json('data.id');

        return Organization::query()->findOrFail($id);
    }
}
