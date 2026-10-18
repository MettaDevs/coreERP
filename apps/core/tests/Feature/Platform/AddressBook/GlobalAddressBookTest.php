<?php

namespace Tests\Feature\Platform\AddressBook;

use App\Platform\AddressBook\Models\Party;
use App\Platform\Geography\Models\AddressHierarchy\District;
use App\Platform\Geography\Models\AddressHierarchy\Province;
use App\Platform\Geography\Models\AddressHierarchy\Regency;
use App\Platform\Geography\Models\AddressHierarchy\Village;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Actions\RegisterBusiness;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GlobalAddressBookTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);
        $this->owner = $this->owner('directory@example.test');
    }

    public function test_mononym_gets_a_party_number_and_creation_replay_is_a_noop(): void
    {
        $created = $this->createParty(['type' => 'person', 'name' => 'Mononym', 'last_name' => null], 'party-mononym-1')
            ->assertCreated()->assertJsonPath('data.number', 'PIHK00001')->assertJsonPath('data.last_name', null);
        $this->createParty(['type' => 'person', 'name' => 'Mononym'], 'party-mononym-1')->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseCount('parties', 1);
    }

    public function test_directory_page_receives_the_controller_data_instead_of_rendering_without_props(): void
    {
        $this->actingAs($this->owner)->get('/settings/global-address-book?q=directory')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/address-book/index')
                ->where('filters.q', 'directory')
                ->has('parties.data')
                ->has('partyTypes')
                ->has('addressBooks')
                ->has('referenceValues')
                ->has('relationshipTypes'));
    }

    public function test_shared_party_rename_reaches_vendor_and_rejects_stale_party_changes(): void
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => 'Test company', 'company_code' => 'TEST', 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();
        $entity = (string) DB::table('organizations')->where('classification', 'legal_entity')->value('id');
        $party = $this->createParty()->assertCreated()->json('data');
        $vendor = $this->actingAs($this->owner)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $entity, 'party_id' => $party['id'],
        ])->assertCreated()->json('data');
        $this->patchJson('/api/v1/parties/'.$party['id'], [
            'name' => 'Renamed supplier', 'type' => $party['type'], 'status' => 'active', 'version' => $party['version'],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed supplier');
        $this->getJson('/api/v1/parties/'.$party['id'])->assertOk()->assertJsonPath('data.vendors.0.id', $vendor['id']);
        $this->assertDatabaseHas('parties', ['id' => $party['id'], 'name' => 'Renamed supplier']);
        $this->patchJson('/api/v1/parties/'.$party['id'], [
            'name' => 'Stale name', 'type' => $party['type'], 'status' => 'active', 'version' => $party['version'],
        ])->assertConflict();
        $current = Party::query()->findOrFail($party['id']);
        $this->deleteJson('/api/v1/parties/'.$party['id'], ['version' => $current->version])->assertUnprocessable();
    }

    public function test_directory_and_vendor_creation_use_separate_retry_namespaces(): void
    {
        $party = $this->createParty(['name' => 'Directory identity'], 'vendor:namespace-test-1')->assertCreated()->json('data');
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => 'Namespace test company', 'company_code' => 'NSPC', 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();
        $entity = (string) DB::table('legal_entities')->where('company_code', 'NSPC')->value('organization_id');
        $vendor = $this->postJson('/api/v1/vendors', ['legal_entity_id' => $entity, 'party_name' => 'Vendor identity'], ['Idempotency-Key' => 'namespace-test-1'])->assertCreated()->json('data');
        $this->assertNotSame($party['id'], $vendor['party_id']);
        $this->assertSame('Vendor identity', $vendor['name']);
    }

    public function test_vendor_lookup_finds_full_name_when_the_search_name_is_custom(): void
    {
        $party = $this->createParty(['name' => 'Full supplier name', 'search_name' => 'short alias'])->assertCreated()->json('data');
        $this->getJson('/api/v1/vendors/party-options?q=Full%20supplier')->assertOk()->assertJsonPath('data.0.id', $party['id']);
    }

    public function test_party_name_changes_preserve_a_custom_search_name(): void
    {
        $party = $this->createParty(['search_name' => 'supplier alias'])->assertCreated()->json('data');
        $this->patchJson('/api/v1/parties/'.$party['id'], [
            'name' => 'Renamed supplier', 'type' => $party['type'], 'status' => 'active', 'version' => $party['version'],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed supplier')->assertJsonPath('data.search_name', 'supplier alias');
    }

    public function test_party_tax_number_is_shared_by_vendor_accounts_and_omitted_vendor_fields_do_not_clear_it(): void
    {
        $party = $this->createParty(['tax_number' => '01.234.567.8-901.000'])->assertCreated()
            ->assertJsonPath('data.language', 'id')->json('data');
        $vendors = [];
        foreach (['TAXA', 'TAXB'] as $code) {
            $this->post('/settings/organization/organizations', [
                'classification' => 'legal_entity', 'name' => 'Tax test '.$code, 'company_code' => $code, 'country_code' => 'ID',
            ])->assertSessionHasNoErrors();
            $entity = (string) DB::table('legal_entities')->where('company_code', $code)->value('organization_id');
            $vendors[] = $this->postJson('/api/v1/vendors', ['legal_entity_id' => $entity, 'party_id' => $party['id']],
                ['Idempotency-Key' => 'party-tax-'.$code])->assertCreated()->assertJsonPath('data.tax_number', $party['tax_number'])->json('data');
        }

        $this->patchJson('/api/v1/parties/'.$party['id'], [
            'name' => $party['name'], 'type' => $party['type'], 'status' => 'active', 'version' => $party['version'],
            'tax_number' => '1234567890123456',
        ])->assertOk()->assertJsonPath('data.tax_number', '1234567890123456');
        foreach ($vendors as $vendor) {
            $current = DB::table('vendors')->find($vendor['id']);
            $this->assertNotNull($current);
            $this->assertSame('1234567890123456', $current->tax_number);
            $this->assertGreaterThan($vendor['version'], $current->version);
            $this->patchJson('/api/v1/vendors/'.$vendor['id'], [
                'name' => $party['name'], 'status' => 'active', 'version' => $current->version,
            ])->assertOk()->assertJsonPath('data.tax_number', '1234567890123456');
        }
    }

    public function test_archiving_unused_party_keeps_the_row_and_hides_it_from_the_directory(): void
    {
        $party = $this->createParty()->assertCreated()->json('data');
        $this->deleteJson('/api/v1/parties/'.$party['id'], ['version' => $party['version']])->assertNoContent();
        $this->assertSoftDeleted('parties', ['id' => $party['id']]);
        $this->getJson('/api/v1/parties/'.$party['id'])->assertNotFound();
        $archived = Party::withTrashed()->findOrFail($party['id']);
        $this->postJson('/api/v1/parties/'.$party['id'].'/restore', ['version' => $archived->version])->assertOk()
            ->assertJsonPath('data.id', $party['id'])->assertJsonPath('data.number', $party['number']);
        $this->getJson('/api/v1/parties/'.$party['id'])->assertOk();
        $this->createParty([], 'archived-party-retry')->assertCreated();
    }

    public function test_foreign_tenant_cannot_read_edit_or_link_a_party(): void
    {
        $party = $this->createParty()->assertCreated()->json('data');
        $other = $this->owner('other-directory@example.test');
        $this->actingAs($other)->getJson('/api/v1/parties/'.$party['id'])->assertNotFound();
        $this->patchJson('/api/v1/parties/'.$party['id'], ['version' => $party['version']])->assertNotFound();
        $ownParty = $this->createParty()->assertCreated()->json('data');
        $this->postJson('/api/v1/parties/'.$ownParty['id'].'/relationships', [
            'from_party_id' => $ownParty['id'], 'to_party_id' => $party['id'], 'relationship_type_code' => 'branch', 'valid_from' => '2026-01-01',
        ])->assertNotFound();
    }

    public function test_book_archive_preserves_party_and_removes_active_membership(): void
    {
        $book = $this->actingAs($this->owner)->postJson('/api/v1/address-books', ['name' => 'Test directory'])->assertCreated()->json('data');
        $party = $this->createParty(['address_book_ids' => [$book['id']]])->assertCreated()->json('data');
        $this->deleteJson('/api/v1/address-books/'.$book['id'], ['version' => $book['version']])->assertNoContent();
        $this->getJson('/api/v1/parties/'.$party['id'])->assertOk()->assertJsonCount(0, 'data.address_book_ids');
        $this->assertSoftDeleted('party_address_books', ['party_id' => $party['id'], 'address_book_id' => $book['id']]);
    }

    public function test_indonesian_address_components_are_persisted_and_formatted(): void
    {
        $party = $this->createParty()->assertCreated()->json('data');
        $this->postJson('/api/v1/parties/'.$party['id'].'/locations', [
            'name' => 'Office', 'country_region_code' => 'ID', 'street' => 'Test street',
            'district' => 'Test district', 'village' => 'Test village', 'rt' => '001', 'rw' => '002', 'purposes' => ['business', 'invoice'],
        ])->assertCreated()->assertJsonPath('data.village', 'Test village')->assertJsonPath('data.rt', '001')->assertJsonPath('data.is_primary', true);
        $this->getJson('/api/v1/parties/'.$party['id'].'/locations')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('postal_addresses', ['village' => 'Test village', 'rt' => '001', 'rw' => '002']);
    }

    public function test_address_region_ids_resolve_master_names_and_reject_an_inconsistent_parent(): void
    {
        $party = $this->createParty()->assertCreated()->json('data');
        $province = Province::create(['country_code' => 'ID', 'code' => 'TEST', 'name' => 'Test province']);
        $regency = Regency::create(['province_id' => $province->id, 'code' => 'TEST', 'name' => 'Test city']);
        $district = District::create(['regency_id' => $regency->id, 'code' => 'TEST', 'name' => 'Test district']);
        $village = Village::create(['district_id' => $district->id, 'code' => 'TEST', 'name' => 'Test village']);
        $payload = ['name' => 'Test office', 'country_region_code' => 'ID', 'purposes' => ['business'],
            'province_id' => $province->id, 'regency_id' => $regency->id, 'district_id' => $district->id, 'village_id' => $village->id,
            'province' => 'Forged name', 'city' => 'Forged city'];
        $this->postJson('/api/v1/parties/'.$party['id'].'/locations', $payload)->assertCreated()
            ->assertJsonPath('data.province_id', $province->id)->assertJsonPath('data.province', 'Test province')
            ->assertJsonPath('data.city', 'Test city')->assertJsonPath('data.village_id', $village->id);
        $other = Province::create(['country_code' => 'ID', 'code' => 'OTHER', 'name' => 'Other province']);
        $this->postJson('/api/v1/parties/'.$party['id'].'/locations', [...$payload, 'province_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('regency_id');
        $this->assertDatabaseCount('party_locations', 1);
    }

    public function test_new_party_saves_addresses_and_linked_contacts_atomically_and_replay_adds_nothing(): void
    {
        $details = [
            'locations' => [['key' => 'office', 'name' => 'Office', 'country_region_code' => 'id', 'street' => 'Office street', 'purposes' => ['business']]],
            'contacts' => [['type' => 'email', 'value' => 'office@example.test', 'address_key' => 'office']],
        ];
        $created = $this->createParty(['party_details' => $details], 'party-aggregate-1')->assertCreated()->json('data');
        $link = DB::table('party_locations')->where('party_id', $created['id'])->first();
        $this->assertNotNull($link);
        $this->assertDatabaseHas('postal_addresses', ['location_id' => $link->location_id, 'country_region_code' => 'ID', 'street' => 'Office street']);
        $this->assertDatabaseHas('electronic_addresses', ['location_id' => $link->location_id, 'value' => 'office@example.test']);
        $this->createParty(['party_details' => $details], 'party-aggregate-1')->assertOk()->assertJsonPath('data.id', $created['id']);
        $this->assertSame(1, DB::table('party_locations')->where('party_id', $created['id'])->count());
        $this->assertSame(1, DB::table('electronic_addresses')->where('location_id', $link->location_id)->count());
        $this->getJson('/api/v1/parties/metadata')->assertOk()->assertJsonStructure(['data' => ['locationPurposes', 'countries', 'contactTypes']]);
    }

    public function test_invalid_staged_region_rolls_back_party_addresses_and_number(): void
    {
        $before = DB::table('parties')->count();
        $this->createParty(['party_details' => ['locations' => [
            ['name' => 'First valid office', 'country_region_code' => 'ID', 'purposes' => ['business']],
            ['name' => 'Invalid region', 'country_region_code' => 'ID', 'purposes' => ['business'], 'province_id' => (string) str()->ulid()],
        ]]], 'party-aggregate-retry')->assertUnprocessable()->assertJsonValidationErrors('party_details.locations.1.province_id');
        $this->assertSame($before, DB::table('parties')->count());
        $this->assertDatabaseMissing('locations', ['name' => 'First valid office']);
        $this->createParty([], 'party-aggregate-retry')->assertCreated()->assertJsonPath('data.number', 'PIHK00001');
    }

    public function test_staged_contact_requires_a_known_draft_address_and_valid_value(): void
    {
        $before = DB::table('parties')->count();
        $this->createParty(['party_details' => ['contacts' => [['type' => 'email', 'value' => 'invalid']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('party_details.contacts.0.value');
        $this->createParty(['party_details' => ['contacts' => [['type' => 'phone', 'value' => '123', 'address_key' => 'missing']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('party_details.contacts.0.address_key');
        $this->assertSame($before, DB::table('parties')->count());
    }

    /** @param array<string, mixed> $changes */
    private function createParty(array $changes = [], ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/parties', ['type' => 'organization', 'name' => 'Test supplier', 'status' => 'active', ...$changes], ['Idempotency-Key' => $key ?? (string) str()->ulid()]);
    }

    private function owner(string $email): User
    {
        $owner = app(RegisterBusiness::class)->handle(['name' => 'Test owner', 'business_name' => 'Test business '.$email, 'email' => $email, 'password' => 'password', 'app_ids' => ['app-uji']]);
        $this->actingAs($owner);

        return $owner;
    }
}
