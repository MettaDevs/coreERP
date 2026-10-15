<?php

namespace Tests\Feature\Platform\Geography\AddressSetup;

use App\Platform\ControlPlane\Models\Client;
use App\Platform\Geography\Models\AddressHierarchy\Province;
use App\Platform\Geography\Models\AddressHierarchy\Regency;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class AddressHierarchyTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $import = require database_path('data/import_indonesia_hierarchy.php');
        $import(base_path('tests/Fixtures/indonesia-address'));

        $this->user = User::factory()->create();
        $client = Client::create([
            'legal_name' => 'Demo Enterprise',
            'slug' => 'demo-enterprise',
            'status' => 'active',
        ]);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'Demo Enterprise',
            'slug' => 'demo-enterprise',
            'status' => 'active',
        ]);
        $this->makeOwner(TenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]));
    }

    public function test_indonesia_address_hierarchy_is_seeded(): void
    {
        $this->assertDatabaseHas('country_regions', [
            'code' => 'ID',
            'name' => 'Indonesia',
        ]);

        $this->assertDatabaseHas('ref_provinces', [
            'country_code' => 'ID',
            'code' => '31',
            'name' => 'Daerah Khusus Ibukota Jakarta',
        ]);

        $this->assertDatabaseHas('ref_regencies', [
            'code' => '3171',
            'name' => 'Kota Administrasi Jakarta Pusat',
        ]);

        $this->assertDatabaseHas('ref_districts', [
            'code' => '317101',
            'name' => 'Gambir',
        ]);

        $this->assertDatabaseHas('ref_villages', [
            'code' => '3171011001',
            'name' => 'Gambir',
            'postal_code' => '10110',
        ]);
    }

    public function test_can_load_address_setup_index_page(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['workspace_tenant_id' => $this->tenant->id])
            ->get('/settings/address-setup');

        $response->assertOk();
    }

    public function test_tenant_cannot_create_a_shared_province(): void
    {
        $count = Province::query()->count();
        $response = $this->actingAs($this->user)
            ->withSession(['workspace_tenant_id' => $this->tenant->id])
            ->post('/settings/address-setup/provinces', [
                'country_code' => 'ID',
                'code' => 'TEST-PROVINCE',
                'name' => 'Provinsi uji',
            ]);

        // Master wilayah bersama hanya diisi migration/import, bukan rute tenant.
        $response->assertNotFound();
        $this->assertSame($count, Province::query()->count());
        $this->assertDatabaseMissing('ref_provinces', ['code' => 'TEST-PROVINCE']);
    }

    public function test_tenant_cannot_create_a_shared_regency(): void
    {
        $province = Province::where('code', '31')->firstOrFail();
        $count = Regency::query()->count();

        $response = $this->actingAs($this->user)
            ->withSession(['workspace_tenant_id' => $this->tenant->id])
            ->post('/settings/address-setup/regencies', [
                'province_id' => $province->id,
                'code' => '3199',
                'name' => 'Kota uji',
                'type' => 'kota',
            ]);

        $response->assertNotFound();
        $this->assertSame($count, Regency::query()->count());
        $this->assertDatabaseMissing('ref_regencies', ['province_id' => $province->id, 'code' => '3199']);
    }
}
