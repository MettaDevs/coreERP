<?php

namespace Tests\Feature;

use Database\Seeders\AppCatalogSeeder;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
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
            ->assertSee('App Uji');
    }

    public function test_portal_only_points_at_contracts_of_registered_apps(): void
    {
        // Contract dimiliki repository app, jadi portal mengarahkan ke URL yang
        // didaftarkan app — bukan menyajikan file dari repository platform ini.
        $this->get('/docs/openapi/app-uji')
            ->assertRedirect('https://contracts.example.test/app-uji/openapi.yaml');

        $this->get('/docs/openapi/not-an-app')->assertNotFound();
    }
}
