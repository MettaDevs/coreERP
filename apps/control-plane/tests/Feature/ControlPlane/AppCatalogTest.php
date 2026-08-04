<?php

namespace Tests\Feature\ControlPlane;

use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_only_the_management_aset_seed_app(): void
    {
        $this->seed(AppCatalogSeeder::class);

        $this->getJson('/api/v1/control/apps')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'management-aset')
            ->assertJsonPath('data.0.database_name', 'app_erp_management_aset')
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('permissions', ['code' => 'management-aset.entitas-aset.read']);
        $this->assertDatabaseMissing('permissions', ['code' => 'management-aset.asset.read']);
        $this->assertSame(8, DB::table('permissions')->where('app_id', 'management-aset')->count());
    }
}
