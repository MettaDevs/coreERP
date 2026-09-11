<?php

namespace Tests\Feature\Settings;

use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\District;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use App\Models\ReferenceData\AddressHierarchy\Village;
use App\Models\User;
use Database\Seeders\IndonesianAddressHierarchySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IndonesianAddressHierarchySeeder::class);
        $this->user = User::factory()->create();
    }

    /** Test 1 — Top Down Traversal: Indonesia -> Bali -> Badung -> Kuta Selatan -> Benoa */
    public function test_top_down_hierarchy_traversal(): void
    {
        $country = Country::where('code', 'ID')->first();
        $this->assertNotNull($country);

        $bali = Province::where('country_code', $country->code)->where('code', '51')->first();
        $this->assertNotNull($bali);
        $this->assertEquals('Bali', $bali->name);

        $badung = Regency::where('province_id', $bali->id)->where('code', '5103')->orWhere('code', '51.03')->first();
        if (! $badung) {
            $badung = Regency::where('province_id', $bali->id)->where('name', 'like', '%Badung%')->first();
        }
        $this->assertNotNull($badung);

        $kutaSelatan = District::where('regency_id', $badung->id)->where('name', 'like', '%Kuta Selatan%')->first();
        $this->assertNotNull($kutaSelatan);

        $benoa = Village::where('district_id', $kutaSelatan->id)->where('name', 'like', '%Benoa%')->first();
        $this->assertNotNull($benoa);
    }

    /** Test 2 — Bottom Up Lineage: Benoa -> Kuta Selatan -> Badung -> Bali -> Indonesia */
    public function test_bottom_up_lineage_resolution(): void
    {
        $benoa = Village::where('name', 'like', '%Benoa%')->first();
        $this->assertNotNull($benoa);

        $response = $this->actingAs($this->user)->getJson(route('address-setup.lookup.bottom-up', [
            'type' => 'village',
            'id' => $benoa->id,
        ]));

        $response->assertOk();
        $data = $response->json();

        $this->assertEquals('Benoa', $data['village']['name']);
        $this->assertStringContainsString('Kuta Selatan', $data['district']['name']);
        $this->assertStringContainsString('Badung', $data['regency']['name']);
        $this->assertEquals('Bali', $data['province']['name']);
        $this->assertEquals('Indonesia', $data['country']['name']);
    }

    /** Test 3 — State/Province to Country auto-resolution */
    public function test_province_resolves_country_automatically(): void
    {
        $bali = Province::where('name', 'Bali')->first();
        $this->assertNotNull($bali);

        $response = $this->actingAs($this->user)->getJson(route('address-setup.lookup.bottom-up', [
            'type' => 'province',
            'id' => $bali->id,
        ]));

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals('ID', $data['country']['code']);
        $this->assertEquals('Indonesia', $data['country']['name']);
    }

    /** Test 4 — Duplicate validation rejected under same parent */
    public function test_duplicate_name_under_same_parent_rejected(): void
    {
        $kutaSelatan = District::where('name', 'like', '%Kuta Selatan%')->first();
        $this->assertNotNull($kutaSelatan);

        // Try creating duplicate village with same name under same district
        $response = $this->actingAs($this->user)->post(route('address-setup.villages.store'), [
            'district_id' => $kutaSelatan->id,
            'code' => 'DUPLICATE_CODE_99',
            'name' => 'Benoa',
            'type' => 'kelurahan',
            'active' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    /** Test 5 — Same Name under different parent is permitted */
    public function test_same_name_under_different_parent_permitted(): void
    {
        $gambir = District::where('name', 'like', '%Gambir%')->first();
        $this->assertNotNull($gambir);

        // Create 'Benoa' under Gambir district (different parent)
        $response = $this->actingAs($this->user)->post(route('address-setup.villages.store'), [
            'district_id' => $gambir->id,
            'code' => 'BENOA_GAMBIR_01',
            'name' => 'Benoa',
            'type' => 'kelurahan',
            'active' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ref_villages', [
            'district_id' => $gambir->id,
            'name' => 'Benoa',
        ]);
    }

    /** Test 6 — Delete protection when child exists */
    public function test_delete_parent_with_children_is_protected(): void
    {
        $bali = Province::where('name', 'Bali')->first();
        $this->assertNotNull($bali);

        $response = $this->actingAs($this->user)->delete(route('address-setup.provinces.destroy', $bali->id));
        $response->assertSessionHasErrors('error');

        // Province must still exist in DB
        $this->assertDatabaseHas('ref_provinces', ['id' => $bali->id]);
    }

    /** Test 7 & 8 — Create and Save new Province */
    public function test_create_and_save_new_province(): void
    {
        $response = $this->actingAs($this->user)->post(route('address-setup.provinces.store'), [
            'country_code' => 'SG',
            'code' => 'SG-CR',
            'name' => 'Central Region',
            'active' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ref_provinces', [
            'country_code' => 'SG',
            'code' => 'SG-CR',
            'name' => 'Central Region',
        ]);
    }
}
