<?php

namespace Tests\Feature\ControlPlane;

use App\Models\User;
use Database\Seeders\ProviderAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_admin_can_register_an_app(): void
    {
        config()->set('coreerp.provider.password', 'LocalProviderPassword!123');
        $this->seed(ProviderAdminSeeder::class);
        $provider = User::query()->where('email', config('coreerp.provider.email'))->firstOrFail();

        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', [
                'id' => 'sample-app',
                'name' => 'Sample app',
                'description' => 'Aplikasi untuk memeriksa katalog.',
                'version' => '1.0.0',
                'database_name' => 'core_app_sample',
                'ui_entry' => '/apps/sample-app/',
                'repository_url' => 'https://example.test/sample-app',
                'contract_url' => 'https://example.test/sample-app/openapi.yaml',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', 'sample-app');

        $this->assertDatabaseHas('apps', ['id' => 'sample-app', 'database_name' => 'core_app_sample']);
    }

    public function test_standard_user_cannot_register_an_app(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/provider/apps', [
                'id' => 'sample-app',
                'name' => 'Sample app',
                'version' => '1.0.0',
                'database_name' => 'core_app_sample',
            ])
            ->assertForbidden();
    }
}
