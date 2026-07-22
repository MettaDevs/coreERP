<?php

namespace Tests\Feature\ControlPlane;

use Tests\TestCase;

class ModuleCatalogTest extends TestCase
{
    public function test_it_exposes_the_seed_module_catalog(): void
    {
        $this->getJson('/api/v1/control/modules')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'procurement')
            ->assertJsonPath('data.0.database', 'core_module_procurement')
            ->assertJsonPath('data.1.id', 'management-asset')
            ->assertJsonPath('data.1.database', 'core_module_management_asset');
    }
}
