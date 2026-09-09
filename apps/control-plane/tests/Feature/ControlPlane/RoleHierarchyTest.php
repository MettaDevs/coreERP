<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\CoreApp;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\AppContextToken;
use App\Support\LaunchableAppCatalog;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hierarchy security role mengikuti Dynamics 365: parent mewarisi duty seluruh
 * turunannya, satu role boleh punya banyak parent dan banyak child, dan graph
 * tidak boleh bersiklus.
 */
class RoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $subject;

    private const ENTITAS = 'app-uji.entitas.manage';

    private const GROUP = 'app-uji.group.manage';

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
        $this->subject = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'user_id' => $this->subject->id,
            'system_role' => 'user',
            'status' => 'active',
        ]);
    }

    /** @param list<string> $duties @param list<string> $children */
    private function createRole(string $name, array $duties, array $children = []): Role
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/roles', [
            'name' => $name,
            'duty_codes' => $duties,
            'child_role_ids' => $children,
        ])->assertCreated()->json('data.id');

        return Role::findOrFail($id);
    }

    private function assignToOwner(Role $role): void
    {
        $membership = $this->subject->activeMembership();

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$membership->id}", [
            'system_role' => 'user',
            'assignments' => [['role_id' => $role->id]],
        ])->assertOk();
    }

    /** @return list<string> */
    private function effectivePermissions(): array
    {
        return app(LaunchableAppCatalog::class)
            ->permissionsFor($this->subject->activeMembership()->refresh(), 'app-uji');
    }

    public function test_parent_role_inherits_the_duties_of_its_children(): void
    {
        $child = $this->createRole('Pengelola group aset', [self::GROUP]);
        $parent = $this->createRole('Manajer aset', [self::ENTITAS], [$child->id]);

        $this->assignToOwner($parent);
        $permissions = $this->effectivePermissions();

        // Hak sendiri tetap ada, hak turunan ikut berlaku.
        $this->assertContains('app-uji.entitas.read', $permissions);
        $this->assertContains('app-uji.group.archive', $permissions);
        $this->assertCount(8, $permissions);
    }

    public function test_child_role_does_not_inherit_upward(): void
    {
        $child = $this->createRole('Pengelola group aset', [self::GROUP]);
        $this->createRole('Manajer aset', [self::ENTITAS], [$child->id]);

        $this->assignToOwner($child);
        $permissions = $this->effectivePermissions();

        $this->assertContains('app-uji.group.read', $permissions);
        $this->assertNotContains('app-uji.entitas.read', $permissions);
    }

    public function test_app_navigation_only_contains_permitted_entries(): void
    {
        $role = $this->createRole('Pengelola group aset', [self::GROUP]);
        $this->assignToOwner($role);

        $navigation = app(LaunchableAppCatalog::class)->navigationFor(
            $this->subject->activeMembership()->refresh(),
            CoreApp::query()->findOrFail('app-uji'),
        );

        $this->assertSame('Master data', $navigation[0]['label']);
        $this->assertSame(['group'], array_column($navigation[0]['items'], 'id'));
    }

    public function test_inheritance_reaches_grandchildren_and_survives_a_diamond(): void
    {
        $grandChild = $this->createRole('Pengelola group aset', [self::GROUP]);
        $left = $this->createRole('Cabang kiri', [self::ENTITAS], [$grandChild->id]);
        $right = $this->createRole('Cabang kanan', [self::ENTITAS], [$grandChild->id]);
        $top = $this->createRole('Direktur', [self::ENTITAS], [$left->id, $right->id]);

        $this->assignToOwner($top);
        $permissions = $this->effectivePermissions();

        $this->assertContains('app-uji.group.update', $permissions);
        // Dua jalur menuju satu cucu tidak boleh menggandakan hasil.
        $this->assertSame(array_values(array_unique($permissions)), $permissions);
    }

    public function test_a_deactivated_child_grants_nothing_and_does_not_bridge_to_its_own_children(): void
    {
        $grandChild = $this->createRole('Pengelola group aset', [self::GROUP]);
        $middle = $this->createRole('Perantara', [self::ENTITAS], [$grandChild->id]);
        $parent = $this->createRole('Manajer aset', [self::ENTITAS], [$middle->id]);

        $middle->update(['is_active' => false]);

        $this->assignToOwner($parent);
        $permissions = $this->effectivePermissions();

        $this->assertContains('app-uji.entitas.read', $permissions);
        $this->assertNotContains('app-uji.group.read', $permissions);
    }

    public function test_a_cycle_is_rejected(): void
    {
        $child = $this->createRole('Pengelola group aset', [self::GROUP]);
        $parent = $this->createRole('Manajer aset', [self::ENTITAS], [$child->id]);

        $this->actingAs($this->owner)->putJson("/api/v1/roles/{$child->id}", [
            'name' => 'Pengelola group aset',
            'duty_codes' => [self::GROUP],
            'child_role_ids' => [$parent->id],
        ])->assertStatus(422)->assertJsonValidationErrors('child_role_ids');

        $this->assertDatabaseMissing('security_role_children', [
            'parent_role_id' => $child->id,
            'child_role_id' => $parent->id,
        ]);
    }

    public function test_a_role_cannot_be_its_own_child(): void
    {
        $role = $this->createRole('Manajer aset', [self::ENTITAS]);

        $this->actingAs($this->owner)->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Manajer aset',
            'duty_codes' => [self::ENTITAS],
            'child_role_ids' => [$role->id],
        ])->assertStatus(422)->assertJsonValidationErrors('child_role_ids');
    }

    public function test_a_role_from_another_tenant_cannot_become_a_child(): void
    {
        $otherOwner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner Lain',
            'business_name' => 'PT Lain',
            'app_ids' => ['app-uji'],
            'email' => 'owner@lain.test',
            'password' => 'password',
        ]);
        $foreignId = $this->actingAs($otherOwner)->postJson('/api/v1/roles', [
            'name' => 'Role tenant lain',
            'duty_codes' => [self::GROUP],
        ])->assertCreated()->json('data.id');

        $role = $this->createRole('Manajer aset', [self::ENTITAS]);

        $this->actingAs($this->owner)->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Manajer aset',
            'duty_codes' => [self::ENTITAS],
            'child_role_ids' => [$foreignId],
        ])->assertStatus(422)->assertJsonValidationErrors('child_role_ids');

        $this->assertDatabaseMissing('security_role_children', ['child_role_id' => $foreignId]);
    }

    public function test_inherited_permissions_reach_the_app_through_the_context_token(): void
    {
        config()->set('coreerp.app_context_signing_key', str_repeat('k', 48));

        $child = $this->createRole('Pengelola group aset', [self::GROUP]);
        $parent = $this->createRole('Manajer aset', [self::ENTITAS], [$child->id]);
        $this->assignToOwner($parent);

        $membership = $this->subject->activeMembership()->refresh();
        $catalog = app(LaunchableAppCatalog::class);
        $token = app(AppContextToken::class)->issue(
            $membership,
            'app-uji',
            $catalog->permissionsFor($membership, 'app-uji'),
            null,
            null,
        );

        // App membaca hak dari payload token ini; hak warisan harus sudah ada di
        // dalamnya, karena app tidak mengetahui hierarchy role sama sekali.
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

        $this->assertContains('app-uji.group.archive', $payload['permissions']);
        $this->assertContains('app-uji.entitas.read', $payload['permissions']);
    }

    public function test_clearing_children_removes_the_inherited_access(): void
    {
        $child = $this->createRole('Pengelola group aset', [self::GROUP]);
        $parent = $this->createRole('Manajer aset', [self::ENTITAS], [$child->id]);
        $this->assignToOwner($parent);
        $this->assertContains('app-uji.group.read', $this->effectivePermissions());

        $this->actingAs($this->owner)->putJson("/api/v1/roles/{$parent->id}", [
            'name' => 'Manajer aset',
            'duty_codes' => [self::ENTITAS],
            'child_role_ids' => [],
        ])->assertOk();

        $this->assertSame(0, DB::table('security_role_children')->where('parent_role_id', $parent->id)->count());
        $this->assertNotContains('app-uji.group.read', $this->effectivePermissions());
    }
}
