<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buku alamat berbentuk Dynamics 365: tempat berdiri sendiri dan dipakai beberapa pihak, satu alamat
 * punya beberapa kegunaan, kontak menempel ke tempat, dan melepas alamat tidak membuang tempatnya
 * (Tahap 1 dan GAB-12 pada docs/todo/buku-alamat-global/).
 */
class SharedLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant tempat bersama',
            'app_ids' => ['app-uji'], 'email' => 'owner@tempat.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();
    }

    public function test_one_place_is_used_by_two_organizations_and_changes_once(): void
    {
        $holding = $this->legalEntity('PT Induk');
        $branch = $this->legalEntity('PT Anak');

        $address = $this->addAddress($holding, ['name' => 'Menara Bersama', 'street' => 'Jl. Sudirman No. 1', 'city' => 'Jakarta']);

        // Organisasi kedua memilih tempat yang sama dari daftar, bukan mengetiknya lagi.
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$branch}/sharable-locations")
            ->assertOk()->assertJsonPath('data.0.id', $address['location_id']);
        $linked = $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$branch}/locations", [
            'location_id' => $address['location_id'], 'purposes' => ['business'],
        ])->assertCreated()
            ->assertJsonPath('data.location_id', $address['location_id'])
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.shared_with', 1)
            ->json('data');
        $this->assertNotSame($address['id'], $linked['id'], 'Setiap organisasi punya tautannya sendiri.');
        $this->assertSame(1, DB::table('locations')->count());
        $this->assertSame(1, DB::table('postal_addresses')->count());

        // Mengubah alamat dari satu organisasi mengubahnya bagi yang lain.
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$holding}/locations/{$address['id']}", [
            'name' => 'Menara Bersama', 'purposes' => ['business'], 'country_region_code' => 'ID',
            'street' => 'Jl. Sudirman No. 2', 'city' => 'Jakarta',
        ])->assertOk();
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$branch}/print-identity")
            ->assertOk()->assertJsonPath('data.address_lines', ['Jl. Sudirman No. 2', 'Jakarta']);

        // Tempat yang sudah ditautkan tidak ditawarkan lagi kepada organisasi yang sama.
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$branch}/locations", [
            'location_id' => $address['location_id'], 'purposes' => ['delivery'],
        ])->assertCreated()->assertJsonPath('data.id', $linked['id']);
        $this->assertSame(2, DB::table('party_locations')->whereNull('deleted_at')->count());
    }

    public function test_releasing_an_address_archives_the_link_and_keeps_the_place(): void
    {
        $holding = $this->legalEntity('PT Induk');
        $branch = $this->legalEntity('PT Anak');
        $address = $this->addAddress($holding, ['name' => 'Gudang', 'street' => 'Jl. Gudang 5']);
        $linked = $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$branch}/locations", [
            'location_id' => $address['location_id'], 'purposes' => ['delivery'],
        ])->assertCreated()->json('data');

        $this->actingAs($this->owner)->deleteJson("/api/v1/organizations/{$holding}/locations/{$address['id']}")->assertNoContent();

        // Tautannya diarsipkan, bukan dihapus; tempat dan alamat posnya tetap untuk pemakai lain.
        $this->assertNotNull(DB::table('party_locations')->where('id', $address['id'])->value('deleted_at'));
        $this->assertNull(DB::table('locations')->where('id', $address['location_id'])->value('deleted_at'));
        $this->assertSame(1, DB::table('postal_addresses')->where('location_id', $address['location_id'])->count());
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$holding}/locations")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$branch}/locations")->assertOk()
            ->assertJsonPath('data.0.id', $linked['id'])
            ->assertJsonPath('data.0.shared_with', 0);
    }

    public function test_one_address_holds_several_purposes(): void
    {
        $organization = $this->legalEntity('PT Kegunaan');
        $address = $this->addAddress($organization, ['name' => 'Kantor', 'purposes' => ['business', 'invoice', 'delivery']]);
        // Urutannya mengikuti daftar kegunaan, bukan urutan pilihan.
        $this->assertSame(['business', 'delivery', 'invoice'], $address['purposes']);

        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$organization}/locations/{$address['id']}", [
            'name' => 'Kantor', 'purposes' => ['delivery'], 'country_region_code' => 'ID', 'street' => 'Jl. Satu',
        ])->assertOk()->assertJsonPath('data.purposes', ['delivery']);

        // Kegunaan yang dicabut diarsipkan, bukan dihapus.
        $this->assertSame(3, DB::table('party_location_purposes')->where('party_location_id', $address['id'])->count());
        $this->assertSame(1, DB::table('party_location_purposes')->where('party_location_id', $address['id'])->whereNull('deleted_at')->count());

        // Kegunaan dipilih lagi setelah dicabut: satu baris aktif, tidak bentrok dengan yang terarsip.
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$organization}/locations/{$address['id']}", [
            'name' => 'Kantor', 'purposes' => ['delivery', 'invoice'], 'country_region_code' => 'ID', 'street' => 'Jl. Satu',
        ])->assertOk()->assertJsonPath('data.purposes', ['delivery', 'invoice']);

        $post = fn (array $purposes) => $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/locations", [
            'name' => 'X', 'purposes' => $purposes, 'country_region_code' => 'ID',
        ]);
        $post(['warehouse'])->assertStatus(422)->assertJsonValidationErrors('purposes.0');
        $post([])->assertStatus(422)->assertJsonValidationErrors('purposes');
    }

    public function test_contacts_belong_to_a_place_and_the_letterhead_prefers_the_primary_one(): void
    {
        $organization = $this->legalEntity('PT Cabang');
        $head = $this->addAddress($organization, ['name' => 'Kantor pusat', 'street' => 'Jl. Pusat 1']);
        $branch = $this->addAddress($organization, ['name' => 'Cabang Bandung', 'street' => 'Jl. Braga 2', 'city' => 'Bandung']);
        $contacts = "/api/v1/organizations/{$organization}/contacts";

        $this->actingAs($this->owner)->postJson($contacts, ['type' => 'phone', 'value' => '022-111', 'address_id' => $branch['id']])
            ->assertCreated()
            ->assertJsonPath('data.address_id', $branch['id'])
            ->assertJsonPath('data.address_name', 'Cabang Bandung')
            ->assertJsonPath('data.is_primary', true);
        // Tanpa alamat pilihan, kontak menempel ke alamat utama.
        $this->actingAs($this->owner)->postJson($contacts, ['type' => 'phone', 'value' => '021-999'])
            ->assertCreated()
            ->assertJsonPath('data.address_id', $head['id'])
            ->assertJsonPath('data.is_primary', true);
        $this->actingAs($this->owner)->postJson($contacts, ['type' => 'email', 'value' => 'bandung@cabang.test', 'address_id' => $branch['id']])
            ->assertCreated();

        // Kop membaca telepon alamat utama, dan email cabang karena alamat utama tidak punya email.
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$organization}/print-identity")
            ->assertOk()
            ->assertJsonPath('data.phone', '021-999')
            ->assertJsonPath('data.email', 'bandung@cabang.test');

        // Alamat milik tenant lain tidak dapat dipakai.
        $this->actingAs($this->owner)->postJson($contacts, ['type' => 'fax', 'value' => '1', 'address_id' => (string) Str::ulid()])
            ->assertNotFound();
    }

    public function test_contacts_without_any_address_wait_on_a_contact_place_that_is_not_an_address(): void
    {
        $organization = $this->legalEntity('PT Tanpa Alamat');
        $contact = $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/contacts", ['type' => 'email', 'value' => 'info@tanpa.test'])
            ->assertCreated()->assertJsonPath('data.address_id', null)->json('data');

        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$organization}/locations")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$organization}/print-identity")
            ->assertOk()->assertJsonPath('data.email', 'info@tanpa.test')->assertJsonPath('data.address_lines', []);

        // Mengarsipkan kontak mengisi deleted_at, bukan membuang barisnya.
        $this->actingAs($this->owner)->deleteJson("/api/v1/organizations/{$organization}/contacts/{$contact['id']}")->assertNoContent();
        $this->assertNotNull(DB::table('electronic_addresses')->where('id', $contact['id'])->value('deleted_at'));
    }

    public function test_a_place_of_another_tenant_cannot_be_linked(): void
    {
        $organization = $this->legalEntity('PT Sendiri');
        $outsider = app(RegisterBusiness::class)->handle([
            'name' => 'Lain', 'business_name' => 'Tenant lain',
            'app_ids' => ['app-uji'], 'email' => 'owner@lain.test', 'password' => 'password',
        ]);
        $foreign = $this->legalEntity('PT Lain', $outsider->activeMembership()->tenant_id);
        $foreignAddress = $this->actingAs($outsider)->postJson("/api/v1/organizations/{$foreign}/locations", [
            'name' => 'Kantor lain', 'purposes' => ['business'], 'country_region_code' => 'ID', 'street' => 'Jl. Lain',
        ])->assertCreated()->json('data');

        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$organization}/sharable-locations")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/locations", [
            'location_id' => $foreignAddress['location_id'], 'purposes' => ['business'],
        ])->assertNotFound();
    }

    public function test_the_database_refuses_an_unknown_party_type(): void
    {
        $this->expectException(QueryException::class);
        DB::table('parties')->insert([
            'id' => strtolower((string) Str::ulid()), 'tenant_id' => $this->membership->tenant_id, 'type' => 'robot',
            'name' => 'X', 'search_name' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function addAddress(string $organization, array $data): array
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/locations", [
            'purposes' => ['business'], 'country_region_code' => 'ID', ...$data,
        ])->assertCreated()->json('data');
    }

    private function legalEntity(string $name, ?string $tenantId = null): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId ?? $this->membership->tenant_id, 'name' => $name,
            'classification' => 'legal_entity', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'organization_id' => $id, 'company_code' => strtoupper(Str::random(4)), 'country_code' => 'ID',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
