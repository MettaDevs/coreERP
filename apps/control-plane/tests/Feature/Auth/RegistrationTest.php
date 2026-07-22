<?php

namespace Tests\Feature\Auth;

use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
        $this->seed(ModuleCatalogSeeder::class);
        Queue::fake();
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'business_name' => 'PT Test',
            'module_ids' => ['procurement', 'management-asset'],
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('tenant_memberships', ['system_role' => 'owner']);
        $this->assertDatabaseCount('tenant_module_entitlements', 2);
        $this->assertDatabaseCount('tenant_deployments', 1);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('roles', 0);
    }
}
