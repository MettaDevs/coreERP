<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Organization;
use App\Models\OrganizationHierarchyNode;
use App\Models\OrganizationHierarchyVersion;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationHierarchyTest extends TestCase
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

    public function test_owner_builds_directory_then_purpose_scoped_versioned_hierarchy(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity',
            'name' => 'PT Metta',
            'company_code' => 'META',
            'country_code' => 'ID',
        ]);
        $department = $this->createOrganization([
            'classification' => 'operating_unit',
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
            'classification' => 'legal_entity', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
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

    public function test_published_hierarchy_can_be_copied_to_an_editable_next_version(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
        ]);
        $department = $this->createOrganization([
            'classification' => 'operating_unit', 'name' => 'Finance', 'operating_unit_type' => 'department',
        ]);
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Management structure', 'purpose_codes' => ['management'],
            'root_organization_id' => $legalEntity->id, 'effective_from' => now()->toDateString(),
        ]);
        $published = OrganizationHierarchyVersion::query()->firstOrFail();
        $this->post("/settings/organization/hierarchy-versions/{$published->id}/placements", [
            'organization_id' => $department->id, 'parent_organization_id' => $legalEntity->id,
        ]);
        $this->post("/settings/organization/hierarchy-versions/{$published->id}/publish");

        $this->post("/settings/organization/hierarchy-versions/{$published->id}/drafts", [
            'effective_from' => now()->addDay()->toDateString(),
        ])->assertRedirect();

        $draft = OrganizationHierarchyVersion::query()->where('status', 'draft')->firstOrFail();
        $this->assertSame(2, $draft->version_number);
        $this->assertCount(2, $draft->nodes);
        $this->assertDatabaseHas('organization_hierarchy_closures', [
            'version_id' => $draft->id,
            'ancestor_organization_id' => $legalEntity->id,
            'descendant_organization_id' => $department->id,
            'distance' => 1,
        ]);
    }

    public function test_organization_cannot_be_placed_twice_in_one_draft(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
        ]);
        $department = $this->createOrganization([
            'classification' => 'operating_unit', 'name' => 'Finance', 'operating_unit_type' => 'department',
        ]);
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Management structure', 'purpose_codes' => ['management'],
            'root_organization_id' => $legalEntity->id, 'effective_from' => now()->toDateString(),
        ]);
        $version = OrganizationHierarchyVersion::query()->firstOrFail();
        $payload = ['organization_id' => $department->id, 'parent_organization_id' => $legalEntity->id];

        $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", $payload)->assertRedirect();
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('organization_id');

        $this->assertDatabaseCount('organization_hierarchy_nodes', 2);
    }

    public function test_owner_can_cancel_a_draft_placement_and_place_it_again(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
        ]);
        $branch = $this->createOrganization([
            'classification' => 'operating_unit', 'name' => 'Branch', 'operating_unit_type' => 'business_unit',
        ]);
        $department = $this->createOrganization([
            'classification' => 'operating_unit', 'name' => 'Front Office', 'operating_unit_type' => 'department',
        ]);
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Management structure', 'purpose_codes' => ['management'],
            'root_organization_id' => $legalEntity->id, 'effective_from' => now()->toDateString(),
        ]);
        $version = OrganizationHierarchyVersion::query()->firstOrFail();

        $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", [
            'organization_id' => $branch->id, 'parent_organization_id' => $legalEntity->id,
        ]);
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", [
            'organization_id' => $department->id, 'parent_organization_id' => $legalEntity->id,
        ]);
        $node = OrganizationHierarchyNode::query()->where('version_id', $version->id)->where('organization_id', $department->id)->firstOrFail();

        $this->delete("/settings/organization/hierarchy-versions/{$version->id}/placements/{$node->id}")->assertRedirect();
        $this->assertDatabaseMissing('organization_hierarchy_nodes', ['id' => $node->id]);
        $this->assertDatabaseMissing('organization_hierarchy_closures', [
            'version_id' => $version->id, 'descendant_organization_id' => $department->id,
        ]);

        $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", [
            'organization_id' => $department->id, 'parent_organization_id' => $branch->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('organization_hierarchy_closures', [
            'version_id' => $version->id,
            'ancestor_organization_id' => $branch->id,
            'descendant_organization_id' => $department->id,
            'distance' => 1,
        ]);
    }

    public function test_draft_accepts_multiple_operating_units_under_the_same_legal_entity(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
        ]);
        $units = collect(['Finance', 'Operations', 'Sales'])->map(fn (string $name) => $this->createOrganization([
            'classification' => 'operating_unit', 'name' => $name, 'operating_unit_type' => 'department',
        ]));
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Management structure', 'purpose_codes' => ['management'],
            'root_organization_id' => $legalEntity->id, 'effective_from' => now()->toDateString(),
        ]);
        $version = OrganizationHierarchyVersion::query()->firstOrFail();

        $units->each(fn (Organization $unit) => $this->post(
            "/settings/organization/hierarchy-versions/{$version->id}/placements",
            ['organization_id' => $unit->id, 'parent_organization_id' => $legalEntity->id],
        )->assertRedirect());

        $this->assertDatabaseCount('organization_hierarchy_nodes', 4);
    }

    public function test_duplicate_hierarchy_name_returns_a_validation_error(): void
    {
        $legalEntity = $this->createOrganization([
            'classification' => 'legal_entity', 'name' => 'PT Metta', 'company_code' => 'META', 'country_code' => 'ID',
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
            'name' => 'Finance',
            'operating_unit_type' => 'department',
        ])->assertForbidden();
    }

    public function test_legal_entity_requires_a_tenant_unique_company_code_but_operating_unit_does_not(): void
    {
        $base = ['classification' => 'legal_entity', 'name' => 'Legal entity', 'country_code' => 'ID'];

        $this->actingAs($this->owner)->postJson('/api/v1/organizations', $base)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_code');

        $this->postJson('/api/v1/organizations', $base + ['company_code' => 'asset-01'])
            ->assertCreated()
            ->assertJsonPath('data.legal_entity.company_code', 'ASSET-01');

        $this->postJson('/api/v1/organizations', $base + ['company_code' => 'ASSET-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_code');

        $this->postJson('/api/v1/organizations', [
            'classification' => 'operating_unit',
            'name' => 'Asset operations',
            'operating_unit_type' => 'department',
        ])->assertCreated();
    }

    public function test_owner_can_update_an_operating_unit_without_changing_its_classification(): void
    {
        $organization = $this->createOrganization([
            'classification' => 'operating_unit', 'name' => 'Kantor Denpasar', 'operating_unit_type' => 'department',
        ]);

        $this->actingAs($this->owner)->patchJson("/api/v1/organizations/{$organization->id}", [
            'name' => 'Unit Operasi Denpasar',
            'operating_unit_type' => 'business_unit',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Unit Operasi Denpasar')
            ->assertJsonPath('data.classification', 'operating_unit')
            ->assertJsonPath('data.operating_unit.type', 'business_unit');
    }

    private function createOrganization(array $data): Organization
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/organizations', $data)->assertCreated()->json('data.id');

        return Organization::query()->findOrFail($id);
    }
}
