<?php

namespace Tests\Feature\ControlPlane;

use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_only_the_management_asset_seed_app(): void
    {
        $this->seed(AppCatalogSeeder::class);

        $this->getJson('/api/v1/control/apps')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'management-asset')
            ->assertJsonPath('data.0.database_name', 'core_app_management_asset')
            ->assertJsonCount(1, 'data');
    }
}
