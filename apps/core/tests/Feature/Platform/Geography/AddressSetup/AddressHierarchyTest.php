<?php

namespace Tests\Feature\Platform\Geography\AddressSetup;

use App\Platform\ControlPlane\Models\Client;
use App\Platform\Geography\Models\AddressHierarchy\Province;
use App\Platform\Geography\Models\AddressHierarchy\Regency;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
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

    public function test_setup_loads_records_in_server_batches_and_searches_beyond_the_first_batch(): void
    {
        for ($index = 0; $index < 45; $index++) {
            Province::create(['country_code' => 'ID', 'code' => 'B'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'name' => 'Batch province '.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'active' => true]);
        }
        $url = '/settings/address-setup/divisions?section=provinces&country=ID&q=Batch';
        $first = $this->actingAs($this->user)->getJson($url)->assertOk()->assertJsonCount(40, 'data');
        $second = $this->getJson($url.'&cursor='.urlencode($first->json('next_cursor')))
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('next_cursor', null);
        $this->assertCount(45, collect([...$first->json('data'), ...$second->json('data')])->unique('id'));
        $this->getJson('/settings/address-setup/divisions?section=provinces&country=ID&q=Batch%20province%20044')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.list_name', 'Batch province 044');
        $this->getJson($url.'&sort=unknown_column')->assertUnprocessable();
        $this->get('/settings/address-setup')->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('countries')->missing('dropdowns')->missing('villages'));
    }

    public function test_parent_lookup_includes_cities_without_mixing_the_county_list(): void
    {
        $province = Province::query()->where('country_code', 'ID')->firstOrFail();
        Regency::create(['province_id' => $province->id, 'code' => 'B001', 'name' => 'Batch city', 'type' => 'city', 'active' => true]);
        $this->actingAs($this->user)->getJson('/settings/address-setup/divisions?section=regencies&q=Batch%20city')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/settings/address-setup/divisions?section=regencies&include_cities=1&q=Batch%20city')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/settings/address-setup/divisions?section=cities&q=Batch%20city')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_lookup_requires_a_known_section_and_bounded_batch(): void
    {
        $this->actingAs($this->user)->getJson('/settings/address-setup/divisions')
            ->assertUnprocessable()->assertJsonValidationErrors('section');
        $this->getJson('/settings/address-setup/divisions?section=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('section');
        $this->getJson('/settings/address-setup/divisions?section=provinces&per_page=100000')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/settings/address-setup/divisions?section=provinces&q[]=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/settings/address-setup/villages-paginated')->assertNotFound();
    }

    public function test_lookup_without_read_permission_cannot_use_legacy_parameters(): void
    {
        $reader = User::factory()->create();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $reader->id, 'status' => 'active']);
        $this->actingAs($reader)
            ->withSession(['workspace_tenant_id' => $this->tenant->id])
            ->getJson('/settings/address-setup/divisions?country_id=ID&level=1')
            ->assertForbidden();
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
