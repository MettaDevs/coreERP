<?php

namespace Tests\Feature\ControlPlane;

use App\Models\Membership;
use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\IndonesianAddressHierarchySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IndonesianAddressHierarchySeeder::class);

        $this->user = User::factory()->create();
        $this->tenant = Tenant::factory()->create();
        Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_indonesia_address_hierarchy_is_seeded(): void
    {
        $this->assertDatabaseHas('ref_countries', [
            'code' => 'ID',
            'name' => 'Indonesia',
        ]);

        $this->assertDatabaseHas('ref_provinces', [
            'country_code' => 'ID',
            'code' => '31',
            'name' => 'DKI Jakarta',
        ]);

        $this->assertDatabaseHas('ref_regencies', [
            'code' => '3171',
            'name' => 'Kota Jakarta Pusat',
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

    public function test_can_create_new_province(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['workspace_tenant_id' => $this->tenant->id])
            ->post('/settings/address-setup/provinces', [
                'country_code' => 'ID',
                'code' => '71',
                'name' => 'Sulawesi Utara',
            ]);

        $this->assertDatabaseHas('ref_provinces', [
            'country_code' => 'ID',
            'code' => '71',
            'name' => 'Sulawesi Utara',
        ]);
    }

    public function test_can_create_new_regency(): void
    {
        $prov = Province::where('code', '32')->firstOrFail();

        $response = $this->actingAs($this->user)
            ->withSession(['workspace_tenant_id' => $this->tenant->id])
            ->post('/settings/address-setup/regencies', [
                'province_id' => $prov->id,
                'code' => '3278',
                'name' => 'Kota Tasikmalaya',
                'type' => 'kota',
            ]);

        $this->assertDatabaseHas('ref_regencies', [
            'province_id' => $prov->id,
            'code' => '3278',
            'name' => 'Kota Tasikmalaya',
        ]);
    }
}
