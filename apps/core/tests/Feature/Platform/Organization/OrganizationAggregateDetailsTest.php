<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Organization;

use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Actions\RegisterBusiness;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrganizationAggregateDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Aggregate owner', 'business_name' => 'Aggregate tenant', 'app_ids' => ['app-uji'],
            'email' => 'organization-draft@example.test', 'password' => 'password',
        ]);
    }

    public function test_company_and_address_and_contact_share_one_party_after_final_save(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/organizations', [
            'classification' => 'legal_entity', 'name' => 'Aggregate company', 'company_code' => 'AGG', 'country_code' => 'ID',
            'party_details' => [
                'locations' => [['key' => 'office', 'name' => 'Company office', 'country_region_code' => 'ID', 'purposes' => ['business']]],
                'contacts' => [['type' => 'phone', 'value' => '021123456', 'address_key' => 'office']],
            ],
        ])->assertCreated();
        $id = DB::table('organizations')->where('name', 'Aggregate company')->value('id');
        $partyId = DB::table('organization_parties')->where('organization_id', $id)->value('party_id');
        $link = DB::table('party_locations')->where('party_id', $partyId)->first();
        $this->assertNotNull($link);
        $this->assertDatabaseHas('locations', ['id' => $link->location_id, 'owner_party_id' => $partyId]);
        $this->assertDatabaseHas('electronic_addresses', ['location_id' => $link->location_id, 'value' => '021123456']);
        $this->getJson('/api/v1/organizations/'.$id.'/locations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/organizations/'.$id.'/contacts')->assertOk()->assertJsonPath('data.0.address_id', $link->id);
    }

    public function test_invalid_details_roll_back_company_party_and_all_prior_details(): void
    {
        $organizations = DB::table('organizations')->count();
        $parties = DB::table('parties')->count();
        $this->actingAs($this->owner)->postJson('/api/v1/organizations', [
            'classification' => 'legal_entity', 'name' => 'Must roll back company', 'company_code' => 'ROLL', 'country_code' => 'ID',
            'party_details' => ['locations' => [
                ['name' => 'Must roll back office', 'country_region_code' => 'ID', 'purposes' => ['business']],
                ['name' => 'Invalid office', 'country_region_code' => 'ID', 'purposes' => ['business'], 'province_id' => (string) str()->ulid()],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('party_details.locations.1.province_id');
        $this->assertSame($organizations, DB::table('organizations')->count());
        $this->assertSame($parties, DB::table('parties')->count());
        $this->assertDatabaseMissing('legal_entities', ['company_code' => 'ROLL']);
        $this->assertDatabaseMissing('locations', ['name' => 'Must roll back office']);
    }

    public function test_operating_unit_can_include_contacts_without_an_address(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/organizations', [
            'classification' => 'operating_unit', 'name' => 'Aggregate department', 'operating_unit_type' => 'department', 'operating_unit_number' => 'DEPT',
            'party_details' => ['contacts' => [['type' => 'email', 'value' => 'department@example.test']]],
        ])->assertCreated();
        $id = DB::table('organizations')->where('name', 'Aggregate department')->value('id');
        $this->getJson('/api/v1/organizations/'.$id.'/contacts')->assertOk()->assertJsonPath('data.0.value', 'department@example.test');
        $this->getJson('/api/v1/organizations/'.$id.'/locations')->assertOk()->assertJsonCount(0, 'data');
    }
}
