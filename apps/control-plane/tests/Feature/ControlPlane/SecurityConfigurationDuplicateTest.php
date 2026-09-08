<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\SecurityDuty;
use App\Models\SecurityPrivilege;
use App\Models\User;
use App\Support\LaunchableAppCatalog;
use Database\Seeders\AppCatalogSeeder;
use Database\Seeders\ReadOnlyRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityConfigurationDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant duplikat',
            'app_ids' => ['management-aset'], 'email' => 'owner@duplicate.test', 'password' => 'password',
        ]);
    }

    /**
     * Skenario "Manager Aset hanya boleh memantau". Tugas akses bawaan aplikasi
     * membundel read, create, dan update; tenant menyempitkannya lewat salinan.
     */
    public function test_a_tenant_narrows_an_app_privilege_to_read_only_through_a_duplicate(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/security-configuration/privileges/management-aset.entitas-aset.maintain/duplicate')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $copy = SecurityPrivilege::query()->where('source', 'custom')->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertSame($this->owner->activeMembership()->tenant_id, $copy->tenant_id);
        $this->assertEqualsCanonicalizing([
            'management-aset.entitas-aset.read',
            'management-aset.entitas-aset.create',
            'management-aset.entitas-aset.update',
        ], $copy->permissions->pluck('code')->all());

        // Bawaan aplikasi tidak boleh ikut berubah.
        $this->assertSame(3, SecurityPrivilege::query()
            ->whereKey('management-aset.entitas-aset.maintain')->sole()->permissions()->count());

        $this->actingAs($this->owner)->put("/settings/security-configuration/privileges/{$copy->code}", [
            'name' => 'Lihat entitas aset saja',
            'permission_codes' => ['management-aset.entitas-aset.read'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['management-aset.entitas-aset.read'], $copy->refresh()->permissions->pluck('code')->all());

        $this->actingAs($this->owner)
            ->post("/settings/security-configuration/privileges/{$copy->code}/publish")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post('/settings/security-configuration/duties', [
            'name' => 'Pantau entitas aset', 'privilege_codes' => [$copy->code],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $duty = SecurityDuty::query()->where('source', 'custom')->sole();
        $this->actingAs($this->owner)
            ->post("/settings/security-configuration/duties/{$duty->code}/publish")
            ->assertRedirect()->assertSessionHasNoErrors();

        $roleId = $this->actingAs($this->owner)->postJson('/api/v1/roles', [
            'name' => 'Manager Aset', 'duty_codes' => [$duty->code],
        ])->assertCreated()->json('data.id');

        // Registrasi bisnis sudah memberi owner role bawaan berisi seluruh duty.
        // Assignment itu dilepas dulu supaya yang diukur benar-benar hak dari
        // role baru, bukan sisa hak lama.
        $membership = $this->owner->activeMembership();
        RoleAssignment::query()->where('membership_id', $membership->id)->delete();
        RoleAssignment::create([
            'membership_id' => $membership->id,
            'role_id' => $roleId,
            'source' => 'manual', 'status' => 'active', 'valid_from' => now(),
        ]);

        $effective = app(LaunchableAppCatalog::class)
            ->permissionsFor($this->owner->activeMembership()->refresh(), 'management-aset');

        $this->assertContains('management-aset.entitas-aset.read', $effective);
        $this->assertNotContains('management-aset.entitas-aset.create', $effective);
        $this->assertNotContains('management-aset.entitas-aset.update', $effective);
    }

    public function test_duplicating_a_duty_copies_its_privileges_as_a_tenant_draft(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/security-configuration/duties/management-aset.entitas-aset.manage/duplicate')
            ->assertRedirect()->assertSessionHasNoErrors();

        $copy = SecurityDuty::query()->where('source', 'custom')->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertEqualsCanonicalizing([
            'management-aset.entitas-aset.maintain',
            'management-aset.entitas-aset.retire',
        ], $copy->privileges->pluck('code')->all());
    }

    public function test_a_draft_can_be_discarded_but_a_published_object_cannot(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/security-configuration/privileges/management-aset.entitas-aset.maintain/duplicate')
            ->assertRedirect()->assertSessionHasNoErrors();

        $copy = SecurityPrivilege::query()->where('source', 'custom')->sole();
        $this->actingAs($this->owner)
            ->delete("/settings/security-configuration/privileges/{$copy->code}")
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, SecurityPrivilege::query()->where('source', 'custom')->count());

        // Bawaan aplikasi bukan milik tenant, jadi tidak dapat dihapus sama sekali.
        $this->actingAs($this->owner)
            ->delete('/settings/security-configuration/privileges/management-aset.entitas-aset.maintain')
            ->assertNotFound();
        $this->assertNotNull(SecurityPrivilege::query()->find('management-aset.entitas-aset.maintain'));
    }

    public function test_the_read_only_role_seeder_grants_read_and_nothing_else(): void
    {
        $this->seed(ReadOnlyRoleSeeder::class);

        $membership = $this->owner->activeMembership();
        $role = Role::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('name', 'Manager Aset')
            ->sole();

        RoleAssignment::query()->where('membership_id', $membership->id)->delete();
        RoleAssignment::create([
            'membership_id' => $membership->id,
            'role_id' => $role->id,
            'source' => 'manual', 'status' => 'active', 'valid_from' => now(),
        ]);

        $effective = app(LaunchableAppCatalog::class)
            ->permissionsFor($membership->refresh(), 'management-aset');

        $this->assertEqualsCanonicalizing([
            'management-aset.entitas-aset.read',
            'management-aset.group-aset.read',
            'management-aset.perencanaan-aset.read',
        ], $effective);
    }

    public function test_a_tenant_cannot_duplicate_an_object_from_an_app_it_is_not_entitled_to(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/security-configuration/privileges/human-resources.pegawai.maintain/duplicate')
            ->assertNotFound();

        $this->assertSame(0, SecurityPrivilege::query()->where('source', 'custom')->count());
    }
}
