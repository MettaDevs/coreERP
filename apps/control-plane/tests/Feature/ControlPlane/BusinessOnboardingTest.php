<?php

namespace Tests\Feature\ControlPlane;

use App\Jobs\DeployAppPlacement;
use App\Models\CoreApp;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
    }

    public function test_api_registration_creates_the_complete_business_boundary_atomically(): void
    {
        $response = $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner Metta',
            'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'],
            'email' => 'owner@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()->assertJsonPath('data.email', 'owner@metta.test');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tenants', 1);
        $tenantId = Tenant::query()->value('id');
        $this->assertDatabaseHas('units_of_measure', ['tenant_id' => $tenantId, 'code' => 'PCS', 'active' => true]);
        $kg = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'KG'])->value('id');
        $gram = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'G'])->value('id');
        $this->assertDatabaseHas('uom_conversions', ['tenant_id' => $tenantId, 'from_unit_id' => $kg, 'to_unit_id' => $gram, 'factor' => 1000]);
        $this->assertDatabaseHas('uom_conversions', ['tenant_id' => $tenantId, 'from_unit_id' => $gram, 'to_unit_id' => $kg, 'factor' => 0.001]);
        $lusin = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'LUSIN'])->value('id');
        $pcs = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'PCS'])->value('id');
        $this->assertDatabaseHas('uom_conversions', ['tenant_id' => $tenantId, 'from_unit_id' => $lusin, 'to_unit_id' => $pcs, 'factor' => 12]);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseHas('tenant_memberships', ['system_role' => 'owner', 'status' => 'active']);
        $this->assertDatabaseCount('tenant_app_entitlements', 1);
        $this->assertDatabaseCount('tenant_deployments', 1);
        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => $tenantId,
            'type' => 'core.tenant.provisioned.v1',
            'correlation_id' => $tenantId,
        ]);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('role_assignments', 1);
        // Role owner menerima seluruh duty app yang menjadi haknya; fixture katalog
        // mendeklarasikan dua master, jadi dua duty.
        $this->assertDatabaseCount('security_role_duties', 2);
    }

    public function test_registration_dispatches_placement_jobs_after_the_transaction_commits(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner Metta',
            'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'],
            'email' => 'after-commit@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        Queue::assertPushed(DeployAppPlacement::class, 1);
    }

    public function test_registration_includes_and_deploys_transitive_app_dependencies_before_the_selected_product(): void
    {
        CoreApp::query()->create([
            'id' => 'business-partner',
            'name' => 'Data pihak bisnis',
            'version' => '1.0.0',
            'status' => 'available',
            'database_name' => 'app_erp_business_partner',
            'has_ui' => false,
        ]);
        DB::table('app_dependencies')->insert([
            'app_id' => 'app-uji',
            'depends_on_app_id' => 'business-partner',
            'version_range' => '^1.0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner Dependency',
            'business_name' => 'PT Dependency',
            'app_ids' => ['app-uji'],
            'email' => 'dependency@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $tenant = Tenant::query()->where('slug', 'pt-dependency')->firstOrFail();
        $this->assertDatabaseHas('tenant_app_entitlements', ['tenant_id' => $tenant->id, 'app_id' => 'business-partner']);
        $this->assertDatabaseHas('tenant_app_entitlements', ['tenant_id' => $tenant->id, 'app_id' => 'app-uji']);
        Queue::assertPushed(DeployAppPlacement::class, 2);
        Queue::assertPushed(DeployAppPlacement::class, fn (DeployAppPlacement $job): bool => $job->appId === 'business-partner');
        Queue::assertPushed(DeployAppPlacement::class, fn (DeployAppPlacement $job): bool => $job->appId === 'app-uji');
    }

    public function test_registration_reuses_an_existing_ready_pooled_placement(): void
    {
        DB::table('app_number_sequence_references')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'app-uji',
            'code' => 'app-uji.entity-code',
            'name' => 'Kode entitas aset',
            'default_prefix' => 'ETA',
            'allowed_scopes' => json_encode(['tenant']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('app_placements')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'app-uji',
            'release_version' => '0.1.0',
            'placement' => 'pooled-primary',
            'profile' => 'pooled',
            'artifact_status' => 'placed',
            'migration_status' => 'succeeded',
            'runtime_status' => 'ready',
            'ready_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Pooled Owner',
            'business_name' => 'Pooled Tenant',
            'app_ids' => ['app-uji'],
            'email' => 'pooled@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        Queue::assertNotPushed(DeployAppPlacement::class);
        $this->assertDatabaseHas('tenant_number_sequences', [
            'status' => 'active',
            'minimum_number' => 0,
            'maximum_number' => 19999,
        ]);
    }

    public function test_duplicate_email_rolls_back_without_creating_a_second_tenant(): void
    {
        $payload = [
            'name' => 'Owner Metta',
            'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'],
            'email' => 'owner@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
        $this->postJson('/api/v1/business-registrations', $payload)->assertCreated();
        $this->postJson('/api/v1/business-registrations', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_registration_only_entitles_selected_apps(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Asset Owner',
            'business_name' => 'Asset Only',
            'app_ids' => ['app-uji'],
            'email' => 'asset@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->assertDatabaseCount('tenant_app_entitlements', 1);
        $this->assertDatabaseHas('tenant_app_entitlements', ['app_id' => 'app-uji']);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('role_assignments', 1);

        $owner = User::query()->where('email', 'asset@metta.test')->firstOrFail();
        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entitledProducts', 1)
                ->where('entitledProducts.0.id', 'app-uji')
                ->where('entitledProducts.0.href', '/apps/app-uji')
                ->has('launchableProducts', 0)
            );
    }

    public function test_owner_can_launch_an_entitled_product_after_the_placement_is_ready(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner',
            'business_name' => 'PT Ready',
            'app_ids' => ['app-uji'],
            'email' => 'ready@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();
        $owner = User::query()->where('email', 'ready@metta.test')->firstOrFail();
        DB::table('app_releases')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'app-uji',
            'version' => '0.1.0',
            'manifest_sha256' => str_repeat('a', 64),
            'edition_image' => 'registry.example/app-uji@sha256:'.str_repeat('a', 64),
            'bundle_path' => 'app-uji/0.1.0',
            'compose_file' => 'compose.yaml',
            'compose_project' => 'app-uji',
            'api_service' => 'app-uji-api',
            'ui_service' => 'app-uji-ui',
            'database_service' => 'app-uji-db',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('app_placements')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'app-uji',
            'release_version' => '0.1.0',
            'placement' => 'pooled-primary',
            'profile' => 'pooled',
            'artifact_status' => 'placed',
            'migration_status' => 'succeeded',
            'runtime_status' => 'ready',
            'ready_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('launchableProducts', 1)
            ->where('launchableProducts.0.id', 'app-uji'));

        $this->actingAs($owner)->getJson('/api/v1/launch-manifest')
            ->assertOk()
            ->assertJsonPath('data.apps.0.id', 'app-uji')
            ->assertJsonPath('data.apps.0.entry', '/apps/app-uji');

        config()->set('coreerp.app_context_signing_key', str_repeat('k', 48));
        $this->actingAs($owner)
            ->get('/apps/app-uji?view=group')
            ->assertInertia(fn (Assert $page) => $page
                ->component('apps/host')
                ->where('app.navigation.rails.0.label', 'Master data')
                ->where('app.navigation.rails.0.items.1.label', 'Group aset')
                ->where('app.navigation.activeItemId', 'group')
                // Entry diturunkan dari placement yang melayani tenant ini, jadi
                // path membawa nama placement — bukan nilai yang pernah disimpan.
                ->where('app.contentEntry', '/apps-content/pooled-primary/app-uji/#/group')
            );

        DB::table('app_placements')->where('app_id', 'app-uji')->update(['runtime_status' => 'starting']);
        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('launchableProducts', 0));
    }

    public function test_ready_placement_without_a_registered_release_is_not_launchable(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner',
            'business_name' => 'Unregistered release',
            'app_ids' => ['app-uji'],
            'email' => 'unregistered@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        DB::table('app_placements')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'app-uji',
            'release_version' => '0.1.0',
            'placement' => 'pooled-primary',
            'profile' => 'pooled',
            'artifact_status' => 'placed',
            'migration_status' => 'succeeded',
            'runtime_status' => 'ready',
            'ready_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = User::query()->where('email', 'unregistered@metta.test')->firstOrFail();
        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('launchableProducts', 0));
    }
}
