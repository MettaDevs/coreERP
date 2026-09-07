<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buku alamat organisasi: alamat utama dan cabang serta informasi kontak legal entity
 * atau operating unit, disimpan pada party organisasi dan dibaca kop dokumen.
 */
class OrganizationAddressBookTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant alamat',
            'app_ids' => ['management-aset'], 'email' => 'owner@alamat.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();
    }

    public function test_first_location_becomes_primary_and_primary_moves_explicitly(): void
    {
        $organization = $this->legalEntity('PT Alamat Jaya');

        $first = $this->actingAs($this->owner)
            ->postJson("/api/v1/organizations/{$organization}/locations", [
                'name' => 'Kantor pusat', 'purpose' => 'business', 'country_region_code' => 'id',
                'street' => 'Jl. Green Lake City Boulevard No. 08', 'building' => 'Rukan CBD Greenlake Blok K',
                'district' => 'Cipondoh', 'city' => 'Tangerang', 'province' => 'Banten', 'postal_code' => '15147',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.country_region_code', 'ID')
            ->assertJsonPath('data.formatted', "Jl. Green Lake City Boulevard No. 08, Rukan CBD Greenlake Blok K\nCipondoh\nTangerang, Banten 15147")
            ->json('data.id');

        // Organisasi kini punya party dengan nama yang sama.
        $this->assertDatabaseHas('organization_parties', ['organization_id' => $organization]);
        $this->assertDatabaseHas('parties', ['tenant_id' => $this->membership->tenant_id, 'name' => 'PT Alamat Jaya', 'type' => 'organization']);

        // Alamat kedua tidak merebut status utama; di luar negeri nama negaranya ikut tercetak.
        $second = $this->actingAs($this->owner)
            ->postJson("/api/v1/organizations/{$organization}/locations", [
                'name' => 'Gudang Singapura', 'purpose' => 'delivery', 'country_region_code' => 'SG',
                'street' => '10 Tuas Avenue', 'city' => 'Singapore', 'postal_code' => '639123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', false)
            ->assertJsonPath('data.formatted', "10 Tuas Avenue\nSingapore 639123\nSingapura")
            ->json('data.id');

        // Menjadikan yang kedua utama menurunkan yang pertama; database menjaga hanya satu utama.
        $this->actingAs($this->owner)
            ->putJson("/api/v1/organizations/{$organization}/locations/{$second}", [
                'name' => 'Gudang Singapura', 'purpose' => 'delivery', 'country_region_code' => 'SG',
                'street' => '10 Tuas Avenue', 'city' => 'Singapore', 'postal_code' => '639123', 'is_primary' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);
        $this->assertSame(1, DB::table('party_locations')->where('is_primary', true)->count());
        $list = $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$organization}/locations")->assertOk();
        $list->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.is_primary', false);
        $this->assertNotEmpty($list->json('meta.countries'));

        // Menghapus yang utama menaikkan lokasi tertua yang tersisa.
        $this->actingAs($this->owner)->deleteJson("/api/v1/organizations/{$organization}/locations/{$second}")->assertNoContent();
        $this->assertTrue((bool) DB::table('party_locations')->where('id', $first)->value('is_primary'));

        // Kode negara yang tidak dikenal ditolak, bukan disimpan sebagai teks bebas.
        $this->actingAs($this->owner)
            ->postJson("/api/v1/organizations/{$organization}/locations", ['name' => 'X', 'purpose' => 'business', 'country_region_code' => 'ZZ'])
            ->assertStatus(422);
    }

    public function test_contacts_keep_one_primary_per_type(): void
    {
        $organization = $this->legalEntity('PT Kontak Jaya');
        $post = fn (array $data) => $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/contacts", $data);

        $phone = $post(['type' => 'phone', 'value' => '(+62) 818-818-818'])->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');
        $post(['type' => 'phone', 'value' => '(021) 555-0100', 'purpose' => 'Bagian penjualan'])->assertCreated()->assertJsonPath('data.is_primary', false);
        $post(['type' => 'whatsapp', 'value' => '0812-0000-1111'])->assertCreated()->assertJsonPath('data.is_primary', true);
        $post(['type' => 'email', 'value' => 'bukan-email'])->assertStatus(422);
        $post(['type' => 'email', 'value' => 'info@kontakjaya.co.id'])->assertCreated();
        $post(['type' => 'telex', 'value' => '1'])->assertStatus(422);

        $this->actingAs($this->owner)->deleteJson("/api/v1/organizations/{$organization}/contacts/{$phone}")->assertNoContent();
        $contacts = collect($this->actingAs($this->owner)->getJson("/api/v1/organizations/{$organization}/contacts")->assertOk()->json('data'));
        $this->assertTrue($contacts->firstWhere('type', 'phone')['is_primary'], 'Telepon yang tersisa menjadi utama.');
        $this->assertSame(['email', 'phone', 'whatsapp'], $contacts->pluck('type')->sort()->values()->all());
    }

    public function test_print_identity_reads_address_and_contacts_from_the_address_book(): void
    {
        $organization = $this->legalEntity('CV Surya Jaya');
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/locations", [
            'name' => 'Kantor', 'purpose' => 'business', 'country_region_code' => 'ID',
            'street' => 'Jl. I Gusti Ngurah Rai', 'city' => 'Badung', 'postal_code' => '80351',
        ])->assertCreated();
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/contacts", ['type' => 'whatsapp', 'value' => '0812-3456-7890'])->assertCreated();
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$organization}/contacts", ['type' => 'url', 'value' => 'https://suryajaya.co.id'])->assertCreated();

        $this->actingAs($this->owner)->getJson("/api/v1/organizations/{$organization}/print-identity")
            ->assertOk()
            ->assertJsonPath('data.display_name', 'CV Surya Jaya')
            ->assertJsonPath('data.address_lines', ['Jl. I Gusti Ngurah Rai', 'Badung 80351'])
            ->assertJsonPath('data.whatsapp', '0812-3456-7890')
            ->assertJsonPath('data.website', 'https://suryajaya.co.id')
            ->assertJsonPath('data.phone', null);
    }

    public function test_members_read_but_only_admins_write_and_other_tenants_see_nothing(): void
    {
        $organization = $this->legalEntity('PT Hak Akses');
        $member = $this->memberWithoutRoles();
        $payload = ['name' => 'Kantor', 'purpose' => 'business', 'country_region_code' => 'ID', 'street' => 'Jl. Satu'];

        $this->actingAs($member)->getJson("/api/v1/organizations/{$organization}/locations")->assertOk();
        $this->actingAs($member)->postJson("/api/v1/organizations/{$organization}/locations", $payload)->assertForbidden();
        $this->actingAs($member)->postJson("/api/v1/organizations/{$organization}/contacts", ['type' => 'phone', 'value' => '1'])->assertForbidden();

        $outsider = app(RegisterBusiness::class)->handle([
            'name' => 'Lain', 'business_name' => 'Tenant lain',
            'app_ids' => ['management-aset'], 'email' => 'owner@lain.test', 'password' => 'password',
        ]);
        $this->actingAs($outsider)->getJson("/api/v1/organizations/{$organization}/locations")->assertNotFound();
        $this->actingAs($outsider)->postJson("/api/v1/organizations/{$organization}/contacts", ['type' => 'phone', 'value' => '1'])->assertNotFound();
    }

    private function legalEntity(string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $this->membership->tenant_id, 'name' => $name,
            'classification' => 'legal_entity', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'organization_id' => $id, 'company_code' => strtoupper(Str::random(4)), 'country_code' => 'ID',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function memberWithoutRoles(): User
    {
        $user = User::factory()->create();
        TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'system_role' => 'member', 'status' => 'active',
        ]);

        return $user;
    }
}
