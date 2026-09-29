<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\InvitationCode;
use App\Models\Organization;
use App\Models\OrganizationHierarchyVersion;
use App\Models\Role;
use App\Models\SecurityPrivilege;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\RowVersion;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengaman edit bersamaan (gap 2, K-03) pada layar pengaturan: konfigurasi keamanan, role, keanggotaan,
 * undangan, workflow, dan organisasi. Setiap kelompok membuktikan tiga hal: penyimpanan kedua dengan versi
 * yang sama ditolak dan isi penyimpanan pertama (termasuk baris anaknya) bertahan, penyimpanan tanpa versi
 * ditolak tanpa mengubah apa pun, dan endpoint baca JSON membawa versi beserta ETag.
 */
class SettingsRowVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant versi',
            'app_ids' => ['app-uji'], 'email' => 'owner@versi.test', 'password' => 'password',
        ]);
    }

    public function test_privilege_draft_saved_twice_from_the_same_version_keeps_the_first_save(): void
    {
        $privilege = $this->privilegeDraft();
        $version = $privilege->version;

        $this->actingAs($this->owner)->from('/settings/security-configuration')
            ->put("/settings/security-configuration/privileges/{$privilege->code}", [
                'name' => 'Dari tab pertama', 'permission_codes' => ['app-uji.entitas.read'], 'version' => $version,
            ])->assertSessionHasNoErrors();
        $this->from('/settings/security-configuration')
            ->put("/settings/security-configuration/privileges/{$privilege->code}", [
                'name' => 'Dari tab kedua', 'permission_codes' => ['app-uji.entitas.create'], 'version' => $version,
            ])->assertRedirect('/settings/security-configuration')
            ->assertSessionHasErrors(['version' => RowVersion::STALE_MESSAGE]);

        $privilege->refresh();
        $this->assertSame('Dari tab pertama', $privilege->name);
        $this->assertSame(['app-uji.entitas.read'], $privilege->permissions->pluck('code')->all());
    }

    public function test_privilege_draft_without_a_version_is_rejected_and_left_unchanged(): void
    {
        $privilege = $this->privilegeDraft();

        $this->actingAs($this->owner)->from('/settings/security-configuration')
            ->put("/settings/security-configuration/privileges/{$privilege->code}", [
                'name' => 'Tanpa versi', 'permission_codes' => ['app-uji.entitas.read'],
            ])->assertSessionHasErrors('version');
        $this->from('/settings/security-configuration')
            ->post("/settings/security-configuration/privileges/{$privilege->code}/publish")
            ->assertSessionHasErrors('version');

        $fresh = $privilege->fresh();
        $this->assertSame($privilege->name, $fresh->name);
        $this->assertSame('draft', $fresh->status);
        $this->assertSame($privilege->version, $fresh->version);
        $this->assertCount(3, $fresh->permissions);
    }

    public function test_duty_draft_saved_twice_from_the_same_version_keeps_the_first_privileges(): void
    {
        $this->actingAs($this->owner)->post('/settings/security-configuration/duties/app-uji.entitas.manage/duplicate')
            ->assertSessionHasNoErrors();
        $duty = DB::table('security_duties')->where('source', 'custom')->first();

        $this->put("/settings/security-configuration/duties/{$duty->code}", [
            'name' => 'Dari tab pertama', 'privilege_codes' => ['app-uji.entitas.maintain'], 'version' => $duty->version,
        ])->assertSessionHasNoErrors();
        $this->put("/settings/security-configuration/duties/{$duty->code}", [
            'name' => 'Dari tab kedua', 'privilege_codes' => ['app-uji.entitas.retire'], 'version' => $duty->version,
        ])->assertSessionHasErrors(['version' => RowVersion::STALE_MESSAGE]);

        $this->assertSame('Dari tab pertama', DB::table('security_duties')->where('code', $duty->code)->value('name'));
        $this->assertSame(['app-uji.entitas.maintain'], DB::table('security_duty_privileges')->where('duty_code', $duty->code)->pluck('privilege_code')->all());
    }

    public function test_role_show_carries_its_version_and_etag(): void
    {
        $role = $this->role('Pemeriksa', ['app-uji.entitas.manage']);

        $this->actingAs($this->owner)->getJson("/api/v1/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('data.version', $role->version)
            ->assertHeader('ETag', RowVersion::etag($role->version));
    }

    public function test_role_saved_twice_from_the_same_version_keeps_the_first_duties(): void
    {
        $role = $this->role('Pemeriksa', ['app-uji.entitas.manage']);

        $saved = $this->actingAs($this->owner)->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Dari tab pertama', 'duty_codes' => ['app-uji.group.manage'],
        ], ['If-Match' => RowVersion::etag($role->version)])->assertOk();
        $this->assertSame($role->fresh()->version, $saved->json('data.version'));

        $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Dari tab kedua', 'duty_codes' => ['app-uji.entitas.manage'], 'version' => $role->version,
        ])->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Tanpa versi', 'duty_codes' => ['app-uji.entitas.manage'],
        ])->assertStatus(428)->assertJsonPath('error.code', 'version_required');
        $this->deleteJson("/api/v1/roles/{$role->id}", ['version' => $role->version])->assertStatus(409);

        $role = $role->fresh();
        $this->assertSame('Dari tab pertama', $role->name);
        $this->assertSame(['app-uji.group.manage'], $role->duties()->pluck('code')->all());
    }

    public function test_membership_show_carries_its_version_and_etag(): void
    {
        $membership = $this->member();

        $this->actingAs($this->owner)->getJson("/api/v1/memberships/{$membership->id}")
            ->assertOk()
            ->assertJsonPath('data.version', $membership->version)
            ->assertHeader('ETag', RowVersion::etag($membership->version));
    }

    public function test_membership_saved_twice_from_the_same_version_keeps_the_first_assignments(): void
    {
        $membership = $this->member();
        $first = $this->role('Pemeriksa', ['app-uji.entitas.manage']);
        $second = $this->role('Pengelola group', ['app-uji.group.manage']);
        $version = $membership->version;

        $saved = $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'assignments' => [['role_id' => $first->id, 'policy_scopes' => []]], 'version' => $version,
        ])->assertOk();
        $this->assertSame($membership->fresh()->version, $saved->json('data.version'));

        $this->patchJson("/api/v1/memberships/{$membership->id}", [
            'assignments' => [['role_id' => $second->id, 'policy_scopes' => []]], 'version' => $version,
        ])->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->patchJson("/api/v1/memberships/{$membership->id}", [
            'assignments' => [],
        ])->assertStatus(428);

        $this->assertSame([$first->id], $membership->roleAssignments()->pluck('role_id')->all());
    }

    public function test_invitation_saved_twice_from_the_same_version_keeps_the_first_roles(): void
    {
        $first = $this->role('Pemeriksa', ['app-uji.entitas.manage']);
        $second = $this->role('Pengelola group', ['app-uji.group.manage']);
        $id = $this->actingAs($this->owner)->postJson('/api/v1/invitation-codes', [
            'label' => 'Awal', 'assignments' => [['role_id' => $first->id, 'policy_scopes' => []]],
        ])->assertCreated()->json('data.id');
        $version = InvitationCode::query()->findOrFail($id)->version;

        $saved = $this->patchJson("/api/v1/invitation-codes/{$id}", [
            'label' => 'Dari tab pertama', 'assignments' => [['role_id' => $second->id, 'policy_scopes' => []]], 'version' => $version,
        ])->assertOk();
        $this->assertSame(InvitationCode::query()->findOrFail($id)->version, $saved->json('data.version'));

        $this->patchJson("/api/v1/invitation-codes/{$id}", [
            'label' => 'Dari tab kedua', 'assignments' => [['role_id' => $first->id, 'policy_scopes' => []]], 'version' => $version,
        ])->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->patchJson("/api/v1/invitation-codes/{$id}", [
            'label' => 'Tanpa versi', 'assignments' => [['role_id' => $first->id, 'policy_scopes' => []]],
        ])->assertStatus(428);
        $this->deleteJson("/api/v1/invitation-codes/{$id}", ['version' => $version])->assertStatus(409);
        $this->deleteJson("/api/v1/invitation-codes/{$id}")->assertStatus(428);

        $invitation = InvitationCode::query()->findOrFail($id);
        $this->assertSame('Dari tab pertama', $invitation->label);
        $this->assertNull($invitation->revoked_at);
        $this->assertSame([$second->id], $invitation->roles()->pluck('roles.id')->all());
    }

    public function test_workflow_graph_carries_the_configuration_etag(): void
    {
        $workflow = $this->workflow();

        $this->actingAs($this->owner)->getJson("/api/v1/workflows/{$workflow->id}/graph")
            ->assertOk()
            ->assertHeader('ETag', RowVersion::etag((int) $workflow->version));
    }

    public function test_workflow_graph_saved_twice_from_the_same_version_keeps_the_first_graph(): void
    {
        $workflow = $this->workflow();
        $version = (int) $workflow->version;

        $saved = $this->actingAs($this->owner)->putJson("/api/v1/workflows/{$workflow->id}/graph", $this->graph('Dari tab pertama') + ['version' => $version])
            ->assertOk();
        $fresh = (int) DB::table('workflow_configurations')->where('id', $workflow->id)->value('version');
        // Di API workflow `data.version` nomor versi konfigurasi; versi baris hanya lewat ETag.
        $this->assertNull($saved->json('data.version'));
        $saved->assertHeader('ETag', RowVersion::etag($fresh));

        $this->putJson("/api/v1/workflows/{$workflow->id}/graph", $this->graph('Dari tab kedua') + ['version' => $version])
            ->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->putJson("/api/v1/workflows/{$workflow->id}/graph", $this->graph('Tanpa versi'))->assertStatus(428);

        $versionId = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->value('id');
        $this->assertSame(['Dari tab pertama'], DB::table('workflow_elements')->where('version_id', $versionId)->where('kind', 'manual_task')->pluck('label')->all());
    }

    public function test_workflow_status_change_from_a_stale_screen_is_rejected(): void
    {
        $workflow = $this->workflow();
        DB::table('workflow_configurations')->where('id', $workflow->id)->update(['name' => 'Diubah orang lain']);

        $this->actingAs($this->owner)->from('/settings/workflows')
            ->post("/settings/workflows/{$workflow->id}/deactivate", ['version' => $workflow->version])
            ->assertRedirect('/settings/workflows')
            ->assertSessionHasErrors(['version' => RowVersion::STALE_MESSAGE]);
        $this->from('/settings/workflows')->post("/settings/workflows/{$workflow->id}/deactivate")
            ->assertSessionHasErrors('version');
    }

    public function test_organization_saved_twice_from_the_same_version_keeps_the_first_save(): void
    {
        $organization = $this->organization(['classification' => 'operating_unit', 'name' => 'Poli Umum', 'operating_unit_type' => 'department']);
        $version = $organization->version;

        $saved = $this->actingAs($this->owner)->patchJson("/api/v1/organizations/{$organization->id}", [
            'name' => 'Dari tab pertama', 'operating_unit_type' => 'department', 'version' => $version,
        ])->assertOk();
        $this->assertSame($organization->fresh()->version, $saved->json('data.version'));

        $this->patchJson("/api/v1/organizations/{$organization->id}", [
            'name' => 'Dari tab kedua', 'operating_unit_type' => 'department', 'version' => $version,
        ])->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->patchJson("/api/v1/organizations/{$organization->id}", [
            'name' => 'Tanpa versi', 'operating_unit_type' => 'department',
        ])->assertStatus(428);

        $this->assertSame('Dari tab pertama', $organization->fresh()->name);
    }

    public function test_hierarchy_placement_from_a_stale_screen_keeps_the_first_placement(): void
    {
        $legalEntity = $this->organization(['classification' => 'legal_entity', 'name' => 'PT Versi', 'company_code' => 'VRS', 'country_code' => 'ID']);
        $first = $this->organization(['classification' => 'operating_unit', 'name' => 'Poli Umum', 'operating_unit_type' => 'department']);
        $second = $this->organization(['classification' => 'operating_unit', 'name' => 'Poli Gigi', 'operating_unit_type' => 'department']);
        $this->post('/settings/organization/hierarchies', [
            'name' => 'Struktur manajemen', 'purpose_codes' => ['management'],
            'root_organization_id' => $legalEntity->id, 'effective_from' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $draft = OrganizationHierarchyVersion::query()->firstOrFail();
        $version = $draft->hierarchy->version;

        $this->from('/settings/organization')->post("/settings/organization/hierarchy-versions/{$draft->id}/placements", [
            'organization_id' => $first->id, 'parent_organization_id' => $legalEntity->id, 'version' => $version,
        ])->assertSessionHasNoErrors();
        $this->from('/settings/organization')->post("/settings/organization/hierarchy-versions/{$draft->id}/placements", [
            'organization_id' => $second->id, 'parent_organization_id' => $legalEntity->id, 'version' => $version,
        ])->assertRedirect('/settings/organization')
            ->assertSessionHasErrors(['version' => RowVersion::STALE_MESSAGE]);
        $this->from('/settings/organization')->post("/settings/organization/hierarchy-versions/{$draft->id}/publish")
            ->assertSessionHasErrors('version');

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertEqualsCanonicalizing(
            [$legalEntity->id, $first->id],
            $draft->nodes()->pluck('organization_id')->all(),
        );
    }

    private function privilegeDraft(): SecurityPrivilege
    {
        $this->actingAs($this->owner)->post('/settings/security-configuration/privileges/app-uji.entitas.maintain/duplicate')
            ->assertSessionHasNoErrors();

        return SecurityPrivilege::query()->where('source', 'custom')->sole();
    }

    /** @param  list<string>  $duties */
    private function role(string $name, array $duties): Role
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/roles', ['name' => $name, 'duty_codes' => $duties])
            ->assertCreated()->json('data.id');

        return Role::query()->findOrFail($id);
    }

    private function member(): TenantMembership
    {
        return TenantMembership::create([
            'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ])->fresh();
    }

    private function workflow(): object
    {
        $typeId = (string) Str::ulid();
        DB::table('workflow_types')->insert([
            'id' => $typeId, 'app_id' => 'app-uji', 'code' => 'app-uji.verifikasi-versi', 'name' => 'Verifikasi versi',
            'decision_context_schema' => json_encode(['required' => []], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->owner)->post('/settings/workflows', ['workflow_type_id' => $typeId, 'name' => 'Workflow versi'])
            ->assertSessionHasNoErrors();

        return DB::table('workflow_configurations')->where('workflow_type_id', $typeId)->sole();
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, string>>} */
    private function graph(string $label): array
    {
        return ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'task', 'type' => 'manual_task', 'data' => ['label' => $label, 'config' => []], 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 400, 'y' => 0]],
        ], 'edges' => [['source' => 'start', 'target' => 'task'], ['source' => 'task', 'target' => 'end']]];
    }

    /** @param  array<string, string>  $data */
    private function organization(array $data): Organization
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/organizations', $data)->assertCreated()->json('data.id');

        return Organization::query()->findOrFail($id);
    }
}
