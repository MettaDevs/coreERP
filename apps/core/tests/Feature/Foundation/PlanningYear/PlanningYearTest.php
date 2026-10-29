<?php

namespace Tests\Feature\Foundation\PlanningYear;

use App\Foundation\PlanningYear\Models\PlanningYear;
use App\Platform\Access\Models\AppDataPolicy;
use App\Platform\Access\Models\RoleAssignmentDataPolicyScope;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\PlanningYearDirectory;
use App\Platform\Modules\Models\CoreApp;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class PlanningYearTest extends TestCase
{
    use GrantsCoreRoles, RefreshDatabase;

    private Tenant $tenant;

    private string $companyId;

    private string $secondCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant();
        $owner = User::factory()->create();
        $membership = $this->makeOwner(TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $owner->id, 'status' => 'active']));
        CoreApp::firstOrCreate(['id' => 'app-uji'], ['name' => 'Aplikasi uji', 'version' => '1.0.0', 'status' => 'active']);
        AppDataPolicy::create(['code' => 'app-uji.planning-years', 'app_id' => 'app-uji', 'name' => 'Perusahaan tahun rencana', 'protected_permissions' => [], 'requires_legal_entity' => true, 'requires_operating_unit' => false, 'allows_descendants' => false]);
        RoleAssignmentDataPolicyScope::create(['tenant_id' => $this->tenant->id, 'role_assignment_id' => $membership->roleAssignments()->firstOrFail()->id, 'policy_code' => 'app-uji.planning-years', 'valid_from' => now()->subMinute()]);
        $this->companyId = $this->company($this->tenant->id);
        $this->secondCompanyId = $this->company($this->tenant->id);
        $this->actingAs($owner);
    }

    public function test_create_is_active_and_replay_preserves_the_original_company_and_year(): void
    {
        $payload = ['legal_entity_id' => $this->companyId, 'year' => 2027];
        $created = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/planning-years', $payload)
            ->assertCreated()->assertJsonPath('data.active', true)->assertJsonPath('data.year', 2027)->json('data');
        $this->postJson('/api/v1/planning-years', $payload)->assertOk()->assertJsonPath('data.id', $created['id']);
        $this->postJson('/api/v1/planning-years', [...$payload, 'year' => 2028])->assertConflict();
    }

    public function test_year_is_unique_within_the_company_but_can_repeat_in_another_company(): void
    {
        $payload = ['legal_entity_id' => $this->companyId, 'year' => 2027];
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/planning-years', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/planning-years', $payload)->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/planning-years', [...$payload, 'legal_entity_id' => $this->secondCompanyId])->assertCreated();
    }

    public function test_year_and_company_are_immutable_and_update_uses_current_version(): void
    {
        $year = $this->source();
        $path = '/api/v1/planning-years/'.$year->id;
        $this->patchJson($path, ['active' => false, 'year' => 2028, 'version' => $year->version])->assertUnprocessable()->assertJsonValidationErrors('year');
        $version = $this->patchJson($path, ['active' => false, 'version' => $year->version])->assertOk()->json('data.version');
        $this->patchJson($path, ['active' => true, 'version' => $year->version])->assertConflict();
        $this->deleteJson($path, ['version' => $version])->assertNoContent();
        $this->assertSoftDeleted('planning_years', ['id' => $year->id]);
    }

    public function test_directory_keeps_selected_inactive_years_and_separates_company_and_tenant(): void
    {
        $active = $this->source();
        $inactive = $this->source(2028);
        $inactive->update(['active' => false]);
        $otherCompany = $this->source(2029, $this->company($this->tenant->id));
        $directory = app(PlanningYearDirectory::class);
        $batch = $directory->lookup($this->tenant->id, $this->companyId, []);
        $this->assertSame([$active->id], $batch['data']->pluck('id')->all());
        $selected = $directory->lookup($this->tenant->id, $this->companyId, ['record_ids' => [$inactive->id]]);
        $this->assertSame([$inactive->id], $selected['data']->pluck('id')->all());
        $this->assertSame([], $directory->resolve($this->tenant->id, $this->companyId, [$otherCompany->id]));
        $this->assertSame([], $directory->resolve($this->tenant()->id, $this->companyId, [$active->id]));
    }

    public function test_foreign_company_and_members_without_management_permission_cannot_create(): void
    {
        $foreign = $this->tenant();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/planning-years', ['legal_entity_id' => $this->company($foreign->id), 'year' => 2027])->assertForbidden();
        $member = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $member->id, 'status' => 'active']);
        $this->actingAs($member)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/planning-years', ['legal_entity_id' => $this->companyId, 'year' => 2027])->assertForbidden();
    }

    public function test_legacy_import_is_repeatable_per_company_and_preserves_inactive_record(): void
    {
        $directory = app(PlanningYearDirectory::class);
        $first = $directory->importLegacy($this->tenant->id, $this->companyId, 2027);
        PlanningYear::query()->findOrFail($first['id'])->update(['active' => false]);
        $again = $directory->importLegacy($this->tenant->id, $this->companyId, 2027);
        $this->assertSame($first['id'], $again['id']);
        $this->assertFalse($again['active']);
        $other = $directory->importLegacy($this->tenant->id, $this->company($this->tenant->id), 2027);
        $this->assertNotSame($first['id'], $other['id']);
    }

    private function source(int $year = 2027, ?string $companyId = null): PlanningYear
    {
        return PlanningYear::query()->create(['tenant_id' => $this->tenant->id, 'legal_entity_id' => $companyId ?? $this->companyId, 'year' => $year, 'active' => true])->refresh();
    }

    private function company(string $tenantId): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $id, 'tenant_id' => $tenantId, 'name' => 'Perusahaan uji', 'classification' => 'legal_entity', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function tenant(): Tenant
    {
        $slug = 'year-'.Str::lower(Str::random(10));
        $client = Client::create(['legal_name' => 'Klien uji', 'slug' => $slug, 'status' => 'active']);

        return Tenant::create(['client_id' => $client->id, 'name' => 'Tenant uji', 'slug' => $slug, 'status' => 'active']);
    }
}
