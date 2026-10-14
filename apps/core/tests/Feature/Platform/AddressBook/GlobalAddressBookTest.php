<?php

namespace Tests\Feature\Platform\AddressBook;

use App\Platform\AddressBook\Models\Party;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Actions\RegisterBusiness;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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
