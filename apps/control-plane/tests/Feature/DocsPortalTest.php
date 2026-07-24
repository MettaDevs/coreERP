<?php

namespace Tests\Feature;

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocsPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(RestrictedDocsAccess::class);
        $this->seed(AppCatalogSeeder::class);
    }

    public function test_portal_lists_control_plane_and_app_contracts(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Control Plane')
            ->assertSee('Management Asset');
    }

    public function test_portal_only_serves_registered_app_contracts(): void
    {
        $this->get('/docs/openapi/management-asset')->assertOk();
        $this->get('/docs/openapi/not-an-app')->assertNotFound();
    }
}
