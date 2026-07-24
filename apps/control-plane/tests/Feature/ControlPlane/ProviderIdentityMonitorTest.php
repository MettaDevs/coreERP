<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Database\Seeders\ProviderAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderIdentityMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_seed_is_idempotent_and_monitor_does_not_grant_tenant_ownership(): void
    {
        config()->set('coreerp.provider.password', 'LocalProviderPassword!123');
        $this->seed(AppCatalogSeeder::class);
        $this->seed(ProviderAdminSeeder::class);
        $this->seed(ProviderAdminSeeder::class);

        $provider = User::where('email', 'provider@coreerp.local')->firstOrFail();
        $this->assertDatabaseCount('provider_access', 1);
        $this->assertNull($provider->activeMembership());

        app(RegisterBusiness::class)->handle([
            'name' => 'Owner',
            'business_name' => 'PT Metta',
            'app_ids' => ['procurement', 'management-asset'],
            'email' => 'owner@metta.test',
            'password' => 'password',
        ]);

        $this->actingAs($provider)
            ->getJson('/api/v1/control/identities')
            ->assertOk()
            ->assertJsonMissing(['password']);
    }

    public function test_tenant_owner_cannot_open_provider_monitor(): void
    {
        $this->seed(AppCatalogSeeder::class);
        $owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner',
            'business_name' => 'PT Metta',
            'app_ids' => ['procurement', 'management-asset'],
            'email' => 'owner@metta.test',
            'password' => 'password',
        ]);
        $this->actingAs($owner)->get('/control/identities')->assertForbidden();
        $this->assertDatabaseCount('provider_access', 0);
    }
}
