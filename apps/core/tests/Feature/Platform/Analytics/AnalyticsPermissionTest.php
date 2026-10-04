<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\SecurityDuty;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Rantai izin engine analitik (KA-14, area 4.6): katalognya terdaftar empat lapis dengan kode persis seperti
 * yang disetujui pemilik produk, hanya role Owner yang memegangnya otomatis — termasuk data pribadi (PQ-04)
 * — dan setiap rute analitik dijaga permission-nya sendiri, menggantikan saklar sementara area 0.
 *
 * Setiap test pernah dilihat merah dengan merusak penangkalnya; caranya ditulis di pull request area 4.
 */
class AnalyticsPermissionTest extends TestCase
{
    use GrantsCoreRoles, RefreshDatabase;

    private const CATALOG_MIGRATION = '2026_10_03_120000_register_analytics_security_catalog.php';

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant izin analitik', 'app_ids' => ['app-uji'],
            'email' => 'owner@izin-analitik.test', 'password' => 'password',
        ]);
        $membership = $this->owner->activeMembership();
        $this->assertNotNull($membership);
        $this->membership = $membership;
    }

    public function test_the_catalog_registers_the_approved_codes_down_to_entry_points(): void
    {
        $duties = SecurityDuty::query()->where('code', 'like', 'core.analytics.%')->with('privileges.permissions')->get()
            ->mapWithKeys(fn (SecurityDuty $duty): array => [$duty->code => $duty->privileges->mapWithKeys(fn ($privilege): array => [
                $privilege->code => $privilege->permissions->map(fn ($permission): string => "{$permission->code}@{$permission->entry_point_code}:{$permission->access_level}")->sort()->values()->all(),
            ])->all()])->sortKeys()->all();

        // Persis tabel *Rantai izin yang diusulkan* di docs/todo/analitik/keamanan.md (KA-14, disetujui 3 Okt 2026).
        $this->assertSame([
            'core.analytics.analyze' => ['core.analytics.dashboard.author' => [
                'core.analytics.dashboard.create@core.analytics.dashboard.form:create',
                'core.analytics.dashboard.read@core.analytics.dashboard.form:read',
                'core.analytics.explore.invoke@core.analytics.explore.form:invoke',
            ]],
            'core.analytics.inquire' => ['core.analytics.dashboard.view' => ['core.analytics.dashboard.read@core.analytics.dashboard.form:read']],
            'core.analytics.manage' => ['core.analytics.shared-dashboard.maintain' => ['core.analytics.shared-dashboard.update@core.analytics.shared-dashboard.form:update']],
            'core.analytics.personal-data' => ['core.analytics.personal-data.view' => ['core.analytics.personal-data.read@core.analytics.personal-data.action:read']],
            'core.analytics.publish' => ['core.analytics.publication.maintain' => [
                'core.analytics.publication.read@core.analytics.publication.form:read',
                'core.analytics.publication.update@core.analytics.publication.form:update',
            ]],
        ], $duties);

        $this->assertSame(
            ['action', 'form'],
            DB::table('app_entry_points')->where('code', 'like', 'core.analytics.%')->distinct()->orderBy('type')->pluck('type')->all(),
        );
        $this->assertSame(0, DB::table('security_duties')->where('code', 'like', 'core.analytics.%')->where('app_id', '!=', CoreSecurityCatalog::APP_ID)->count());
    }

    public function test_only_the_owner_role_holds_analytics_duties_automatically_including_personal_data(): void
    {
        $owner = UserPrincipal::fromMembership($this->membership, 'UTC');
        $this->assertTrue($owner->mayUsePersonalData());
        $this->assertTrue($this->membership->hasCorePermission(CoreSecurityCatalog::ANALYTICS_EXPLORE_INVOKE));
        $this->assertEqualsCanonicalizing(
            ['core.analytics.analyze', 'core.analytics.inquire', 'core.analytics.manage', 'core.analytics.personal-data', 'core.analytics.publish'],
            $this->ownerRole()->duties()->where('code', 'like', 'core.analytics.%')->pluck('code')->all(),
        );

        // Penyusun dasbor memegang analisis bebas, tetapi data pribadi tetap harus diberikan dengan sengaja.
        $analyst = $this->member(['core.analytics.analyze']);
        $this->assertFalse(UserPrincipal::fromMembership($analyst, 'UTC')->mayUsePersonalData());
        $this->assertTrue(UserPrincipal::fromMembership($this->member(['core.analytics.personal-data']), 'UTC')->mayUsePersonalData());

        // Role baru tidak mewarisi satu pun duty analitik.
        $role = Role::query()->create(['tenant_id' => $this->membership->tenant_id, 'name' => 'Staf', 'is_active' => true]);
        $this->assertSame([], $role->duties()->pluck('code')->all());
    }

    public function test_the_catalog_migration_is_idempotent_and_brings_existing_owner_roles_along(): void
    {
        $migration = require database_path('migrations/'.self::CATALOG_MIGRATION);
        $migration->down();
        $this->assertSame(0, DB::table('security_role_duties')->where('duty_code', 'like', 'core.analytics.%')->count());
        $this->assertSame(0, DB::table('permissions')->where('code', 'like', 'core.analytics.%')->count());

        // Tenant yang sudah ada sebelum katalog lahir: Owner-nya mendapat kelima duty saat migration berjalan.
        $migration->up();
        $migration->up();

        $this->assertSame(5, $this->ownerRole()->duties()->where('code', 'like', 'core.analytics.%')->count());
        $this->assertSame(7, DB::table('permissions')->where('code', 'like', 'core.analytics.%')->count());
        $this->assertSame(8, DB::table('security_privilege_permissions')->where('privilege_code', 'like', 'core.analytics.%')->count());
        $this->assertSame(5, DB::table('security_duty_privileges')->where('duty_code', 'like', 'core.analytics.%')->count());
    }

    public function test_every_analytics_route_requires_the_explore_permission(): void
    {
        // Memegang permission module lain dan duty Lihat dasbor, tetapi bukan analisis bebas.
        $viewer = $this->member(['core.analytics.inquire'])->user;
        $withoutAnalytics = $this->member(['core.vendor.inquire'])->user;
        $query = ['dataset' => 'app-uji.data', 'measures' => ['count']];

        foreach ([$viewer, $withoutAnalytics] as $user) {
            $this->actingAs($user)->get('/analytics/explore')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/analytics/query', $query)->assertForbidden();
        }

        // Pemegang analisis bebas melewati gate; yang memutuskan berikutnya engine: dataset ini tidak ada.
        $analyst = $this->member(['core.analytics.analyze'])->user;
        $this->actingAs($analyst)->get('/analytics/explore')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/analytics/explore')
                ->where('auth.membership.permissions', fn ($permissions): bool => collect($permissions)->contains(CoreSecurityCatalog::ANALYTICS_EXPLORE_INVOKE))
                ->missing('analyticsEnabled'));
        $this->actingAs($analyst)->postJson('/api/v1/analytics/query', $query)
            ->assertNotFound()->assertJsonPath('error.code', 'analytics.dataset_unknown');
    }

    private function ownerRole(): Role
    {
        return Role::query()->where('tenant_id', $this->membership->tenant_id)->where('is_owner', true)->sole();
    }

    /** @param list<string> $duties */
    private function member(array $duties): TenantMembership
    {
        $membership = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => User::factory()->create()->id, 'status' => 'active',
        ]);

        return $this->grantDuties($membership, $duties);
    }
}
