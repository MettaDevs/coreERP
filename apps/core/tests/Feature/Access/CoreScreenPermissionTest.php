<?php

namespace Tests\Feature\Access;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Role;
use App\Models\SecurityDuty;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Access\AccessGuards;
use App\Support\Access\CoreSecurityCatalog;
use App\Support\Access\TenantProducts;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Layar setup Core dijaga rantai security role yang sama dengan module (SEC-22, TODO feed posting 7.4): katalog
 * Core terdaftar empat lapis, role Owner selalu memegang semua duty yang sah, dan duty *Lihat* tidak membuka aksi
 * yang butuh duty *Kelola*.
 */
class CoreScreenPermissionTest extends TestCase
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

    public function test_the_core_catalog_registers_twenty_duties_down_to_entry_points(): void
    {
        $duties = SecurityDuty::query()->where('app_id', CoreSecurityCatalog::APP_ID)->with('privileges.permissions')->get();

        // Delapan belas dari katalog layar Core, dua dari log perubahan (Lihat riwayat, Kelola log).
        $this->assertCount(20, $duties);
        foreach ($duties as $duty) {
            $this->assertNotEmpty($duty->privileges, $duty->code);
            foreach ($duty->privileges as $privilege) {
                $this->assertNotEmpty($privilege->permissions, $privilege->code);
                foreach ($privilege->permissions as $permission) {
                    $this->assertNotSame($privilege->code, $permission->code);
                    $this->assertContains($permission->access_level, ['read', 'update', 'invoke']);
                }
            }
        }

        // Duty Lihat hanya membawa read; Kelola membawa read dan ubah.
        $this->assertSame([CoreSecurityCatalog::VENDOR_READ], $this->permissionsOfDuty('core.vendor.inquire'));
        $this->assertSame([CoreSecurityCatalog::VENDOR_READ, CoreSecurityCatalog::VENDOR_UPDATE], $this->permissionsOfDuty('core.vendor.manage'));
        $this->assertSame([CoreSecurityCatalog::FINANCE_POSTING_READ], $this->permissionsOfDuty('core.finance-posting.inquire'));
        $this->assertSame(
            [CoreSecurityCatalog::FINANCE_POSTING_PROCESS, CoreSecurityCatalog::FINANCE_POSTING_READ],
            $this->permissionsOfDuty('core.finance-posting.follow-up'),
        );
        $this->assertSame([CoreSecurityCatalog::CHANGE_LOG_READ], $this->permissionsOfDuty('core.change-log.inquire'));
        $this->assertSame([CoreSecurityCatalog::CHANGE_LOG_READ, CoreSecurityCatalog::CHANGE_LOG_UPDATE], $this->permissionsOfDuty('core.change-log.manage'));
    }

    public function test_the_owner_role_holds_every_duty_including_ones_registered_later(): void
    {
        $ownerRole = $this->ownerRole();
        $this->assertEqualsCanonicalizing(
            TenantProducts::duties($ownerRole->tenant_id)->pluck('code')->all(),
            $ownerRole->duties()->pluck('code')->all(),
        );
        $this->assertContains(CoreSecurityCatalog::ACCESS_MANAGE_DUTY, $ownerRole->duties()->pluck('code')->all());

        // Versi app berikutnya membawa duty baru: Owner mendapatkannya tanpa disunting siapa pun.
        $catalog = config('coreerp.app_catalog');
        $catalog[0]['privileges'][] = ['code' => 'app-uji.perencanaan.view', 'name' => 'Lihat perencanaan', 'permissions' => ['app-uji.perencanaan.read']];
        $catalog[0]['duties'][] = ['code' => 'app-uji.perencanaan.inquire', 'name' => 'Pantau perencanaan', 'privileges' => ['app-uji.perencanaan.view']];
        config()->set('coreerp.app_catalog', $catalog);
        $this->seed(AppCatalogSeeder::class);

        $this->assertContains('app-uji.perencanaan.inquire', $ownerRole->duties()->pluck('code')->all());
    }

    public function test_the_owner_role_receives_a_published_custom_duty(): void
    {
        $this->actingAs($this->owner)->post('/settings/security-configuration/privileges', [
            'name' => 'Lihat perencanaan',
            'permission_codes' => ['app-uji.perencanaan.read'],
        ])->assertRedirect();
        $privilege = DB::table('security_privileges')->where('source', 'custom')->value('code');
        $this->actingAs($this->owner)->post('/settings/security-configuration/duties', [
            'name' => 'Pembaca perencanaan', 'privilege_codes' => [$privilege],
        ])->assertRedirect();
        $duty = SecurityDuty::query()->where('source', 'custom')->sole();

        $this->assertNotContains($duty->code, $this->ownerRole()->duties()->pluck('code')->all(), 'Draf belum sah.');

        $this->actingAs($this->owner)->post("/settings/security-configuration/privileges/{$privilege}/publish", ['version' => DB::table('security_privileges')->where('code', $privilege)->value('version')])->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/security-configuration/duties/{$duty->code}/publish", ['version' => $duty->fresh()->version])->assertRedirect();

        $this->assertContains($duty->code, $this->ownerRole()->duties()->pluck('code')->all());
    }

    public function test_an_inquire_duty_opens_the_screen_but_not_its_changes(): void
    {
        $reader = $this->memberWithDuties(['core.vendor.inquire']);
        $withoutRole = $this->memberWithDuties([]);

        $this->actingAs($reader)->get('/settings/vendors')->assertOk();
        $this->actingAs($reader)->postJson('/api/v1/vendors', [])->assertForbidden();
        $this->actingAs($reader)->get('/settings/access')->assertForbidden();

        $this->actingAs($withoutRole)->get('/settings/vendors')->assertForbidden();
        $this->actingAs($withoutRole)->get('/settings/organization')->assertForbidden();

        // Kelola vendor melewati penjaga izin; yang menolak berikutnya adalah validasi isinya.
        $manager = $this->memberWithDuties(['core.vendor.manage']);
        $this->actingAs($manager)->postJson('/api/v1/vendors', [])->assertUnprocessable();
    }

    public function test_the_shell_receives_only_the_permissions_the_member_holds(): void
    {
        $reader = $this->memberWithDuties(['core.finance-posting.inquire']);

        $this->actingAs($reader)->get('/settings/finance-postings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.membership.permissions', [CoreSecurityCatalog::FINANCE_POSTING_READ])
                ->where('canManage', false));

        $this->actingAs($this->owner)->get('/settings/finance-postings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.membership.permissions', fn ($permissions) => collect($permissions)->contains(CoreSecurityCatalog::ACCESS_UPDATE))
                ->where('canManage', true));
    }

    public function test_former_owners_and_admins_become_owner_role_holders_when_system_role_is_dropped(): void
    {
        $migration = require database_path('migrations/2026_09_25_120200_replace_system_role_with_owner_role.php');
        $migration->down();

        $tenantId = $this->owner->activeMembership()->tenant_id;
        $admin = $this->legacyMember($tenantId, 'admin');
        $plain = $this->legacyMember($tenantId, 'user');
        $inactive = $this->legacyMember($tenantId, 'admin', 'inactive');

        $migration->up();

        $this->assertTrue(AccessGuards::holdsOwnerRole(TenantMembership::query()->findOrFail($admin)));
        $this->assertFalse(AccessGuards::holdsOwnerRole(TenantMembership::query()->findOrFail($plain)));
        $this->assertSame(0, DB::table('role_assignments')->where('membership_id', $inactive)->count());
        // Owner lama tetap satu penugasan Owner, tidak digandakan.
        $this->assertSame(1, DB::table('role_assignments')->where('membership_id', $this->owner->activeMembership()->id)->where('role_id', $this->ownerRole()->id)->count());
        // Admin yang dipindah membawa cakupan data semua kebijakan app yang dibeli, seperti owner saat tenant lahir.
        $this->assertSame(
            DB::table('app_data_policies')->whereIn('app_id', TenantProducts::appIds($tenantId))->count(),
            DB::table('role_assignment_data_policy_scopes')
                ->join('role_assignments', 'role_assignments.id', '=', 'role_assignment_data_policy_scopes.role_assignment_id')
                ->where('role_assignments.membership_id', $admin)
                ->count(),
        );
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('tenant_memberships', 'system_role'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('invitation_codes', 'system_role'));
    }

    private function ownerRole(): Role
    {
        return Role::query()->where('tenant_id', $this->owner->activeMembership()->tenant_id)->where('is_owner', true)->sole();
    }

    /** @return list<string> */
    private function permissionsOfDuty(string $duty): array
    {
        return SecurityDuty::query()->findOrFail($duty)->privileges()->with('permissions')->get()
            ->flatMap(fn ($privilege) => $privilege->permissions->pluck('code'))
            ->unique()->sort()->values()->all();
    }

    /** @param  list<string>  $duties */
    private function memberWithDuties(array $duties): User
    {
        $tenantId = $this->owner->activeMembership()->tenant_id;
        $user = User::factory()->create();
        $membership = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'status' => 'active']);
        if ($duties !== []) {
            $role = Role::create(['tenant_id' => $tenantId, 'name' => 'Role '.Str::random(6), 'is_active' => true]);
            $role->duties()->sync($duties);
            $membership->roleAssignments()->create(['role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()->subMinute()]);
        }

        return $user;
    }

    private function legacyMember(string $tenantId, string $systemRole, string $status = 'active'): string
    {
        $id = (string) Str::ulid();
        DB::table('tenant_memberships')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'user_id' => User::factory()->create()->id,
            'system_role' => $systemRole, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
