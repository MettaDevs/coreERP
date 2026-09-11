<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\RoleAssignment;
use App\Support\DataPolicyAccessResolver;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataPolicyAccessResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_is_unioned_inside_one_policy_without_leaking_to_another_policy(): void
    {
        $this->seed(AppCatalogSeeder::class);
        $user = app(RegisterBusiness::class)->handle([
            'name' => 'Pemilik uji',
            'business_name' => 'Bisnis uji',
            'app_ids' => ['app-uji'],
            'email' => 'pemilik@example.test',
            'password' => 'password',
        ]);
        $membership = $user->activeMembership();
        $assignment = $membership->roleAssignments()->firstOrFail();
        $legalEntityId = $this->organization($membership->tenant_id, 'Entitas legal', 'legal_entity');
        $firstUnitId = $this->organization($membership->tenant_id, 'FO Negarow', 'operating_unit');
        $secondUnitId = $this->organization($membership->tenant_id, 'Sales Negarow', 'operating_unit');

        DB::table('app_data_policies')->insert([
            'code' => 'app-uji.tanggung-jawab',
            'app_id' => 'app-uji',
            'name' => 'Akses aset menurut unit penanggung jawab',
            'protected_permissions' => json_encode(['app-uji.entitas.read'], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => true,
            'requires_operating_unit' => true,
            'allows_descendants' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('app_data_policies')->insert([
            'code' => 'app-uji.audit',
            'app_id' => 'app-uji',
            'name' => 'Akses audit aset',
            'protected_permissions' => json_encode(['app-uji.entitas.read'], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => true,
            'requires_operating_unit' => true,
            'allows_descendants' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->scope($membership->tenant_id, $assignment, 'app-uji.tanggung-jawab', $legalEntityId, $firstUnitId);
        $this->scope($membership->tenant_id, $assignment, 'app-uji.tanggung-jawab', $legalEntityId, $secondUnitId);
        $this->scope($membership->tenant_id, $assignment, 'app-uji.audit', $legalEntityId, $firstUnitId);

        $policies = app(DataPolicyAccessResolver::class)->resolve($membership);

        $this->assertSame([
            ['legal_entity_id' => $legalEntityId, 'operating_unit_ids' => [$firstUnitId]],
            ['legal_entity_id' => $legalEntityId, 'operating_unit_ids' => [$secondUnitId]],
        ], $policies['app-uji.tanggung-jawab']['scope_grants']);
        $this->assertSame([
            ['legal_entity_id' => $legalEntityId, 'operating_unit_ids' => [$firstUnitId]],
        ], $policies['app-uji.audit']['scope_grants']);
    }

    private function organization(string $tenantId, string $name, string $classification): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'classification' => $classification,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function scope(string $tenantId, RoleAssignment $assignment, string $policyCode, string $legalEntityId, string $organizationId): void
    {
        DB::table('role_assignment_data_policy_scopes')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'role_assignment_id' => $assignment->id,
            'policy_code' => $policyCode,
            'legal_entity_id' => $legalEntityId,
            'organization_id' => $organizationId,
            'hierarchy_id' => null,
            'hierarchy_version_id' => null,
            'include_descendants' => false,
            'valid_from' => now(),
            'valid_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
