<?php

namespace Tests\Feature\ControlPlane;

use App\Jobs\DeployAppPlacement;
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
            'app_ids' => ['management-asset'],
            'email' => 'owner@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()->assertJsonPath('data.email', 'owner@metta.test');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseHas('tenant_memberships', ['system_role' => 'owner', 'status' => 'active']);
        $this->assertDatabaseCount('tenant_app_entitlements', 1);
        $this->assertDatabaseCount('tenant_deployments', 1);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('role_assignments', 1);
        $this->assertDatabaseCount('security_role_duties', 3);
    }

    public function test_registration_dispatches_placement_jobs_after_the_transaction_commits(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner Metta',
            'business_name' => 'PT Metta',
            'app_ids' => ['management-asset'],
            'email' => 'after-commit@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        Queue::assertPushed(DeployAppPlacement::class, 1);
    }

    public function test_registration_reuses_an_existing_ready_pooled_placement(): void
    {
        DB::table('app_placements')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'management-asset',
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
            'app_ids' => ['management-asset'],
            'email' => 'pooled@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        Queue::assertNotPushed(DeployAppPlacement::class);
    }

    public function test_duplicate_email_rolls_back_without_creating_a_second_tenant(): void
    {
        $payload = [
            'name' => 'Owner Metta',
            'business_name' => 'PT Metta',
            'app_ids' => ['management-asset'],
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
            'app_ids' => ['management-asset'],
            'email' => 'asset@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->assertDatabaseCount('tenant_app_entitlements', 1);
        $this->assertDatabaseHas('tenant_app_entitlements', ['app_id' => 'management-asset']);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('role_assignments', 1);

        $owner = User::query()->where('email', 'asset@metta.test')->firstOrFail();
        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entitledProducts', 1)
                ->where('entitledProducts.0.id', 'management-asset')
                ->where('entitledProducts.0.href', '/apps/management-asset')
                ->has('launchableProducts', 0)
            );
    }

    public function test_owner_can_launch_an_entitled_product_after_the_placement_is_ready(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner',
            'business_name' => 'PT Ready',
            'app_ids' => ['management-asset'],
            'email' => 'ready@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();
        $owner = User::query()->where('email', 'ready@metta.test')->firstOrFail();
        DB::table('app_placements')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'management-asset',
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
            ->where('launchableProducts.0.id', 'management-asset'));

        $this->actingAs($owner)->getJson('/api/v1/launch-manifest')
            ->assertOk()
            ->assertJsonPath('data.apps.0.id', 'management-asset')
            ->assertJsonPath('data.apps.0.entry', '/apps/management-asset');
    }
}
