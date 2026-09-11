<?php

namespace Tests\Feature\ControlPlane;

use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_only_the_app_uji_seed_app(): void
    {
        $this->seed(AppCatalogSeeder::class);

        $this->getJson('/api/v1/control/apps')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'app-uji')
            ->assertJsonPath('data.0.database_name', 'app_uji')
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('permissions', ['code' => 'app-uji.entitas.read']);
        $this->assertDatabaseMissing('permissions', ['code' => 'app-uji.tidak-terdaftar.read']);
        $this->assertSame(9, DB::table('permissions')->where('app_id', 'app-uji')->count());
    }
}
