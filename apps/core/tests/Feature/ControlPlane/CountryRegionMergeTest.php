<?php

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\CountryRegion;
use App\Models\Party;
use App\Models\PartyLocation;
use App\Models\PostalAddress;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\WorldCountriesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Satu daftar negara, dan hanya satu.
 *
 * Sampai 17 September 2026 ada dua: `country_regions` yang dirujuk alamat pos, dan
 * `ref_countries` yang dirujuk master wilayah. Test ini menjaga agar keduanya tidak
 * tumbuh kembali menjadi dua, dan agar penghapusan negara tidak dapat menarik alamat
 * atau provinsi ikut hilang.
 */
class CountryRegionMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
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
        TenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_ref_countries_no_longer_owns_anything(): void
    {
        // Tabelnya masih ada sebagai bekal mundur satu rilis, tetapi tidak boleh ada
        // satu pun foreign key yang masih menunjuknya.
        $penunjuk = DB::select("
            select tc.table_name
            from information_schema.table_constraints tc
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name
            where tc.constraint_type = 'FOREIGN KEY' and ccu.table_name = 'ref_countries'
        ");
        $this->assertSame([], $penunjuk, 'Masih ada tabel yang menunjuk ref_countries.');

        $indonesia = CountryRegion::query()->find('ID');
        $this->assertNotNull($indonesia);
        $this->assertSame('Indonesia', $indonesia->name);
        $this->assertTrue($indonesia->active);
        $this->assertTrue(Schema::hasColumn('country_regions', 'phone_code'));
        $this->assertTrue(Schema::hasColumn('country_regions', 'timezone'));
    }

    public function test_the_hierarchy_points_at_country_regions(): void
    {
        $province = Province::create([
            'country_code' => 'ID',
            'code' => '31',
            'name' => 'DKI Jakarta',
        ]);

        $this->assertSame('ID', $province->country->code);

        // Negara yang tidak ada ditolak database, bukan diterima diam-diam.
        $this->expectException(QueryException::class);
        Province::create([
            'country_code' => 'ZZ',
            'code' => '99',
            'name' => 'Entah',
        ]);
    }

    public function test_a_tenant_cannot_create_or_delete_a_country(): void
    {
        // Daftar negara dipakai bersama seluruh tenant, jadi layar tenant tidak punya
        // pintu tulisnya sama sekali — bukan sekadar dijaga izin.
        $this->actingAs($this->admin)
            ->post('/settings/address-setup/countries', ['code' => 'ZZ', 'name' => 'Negara Karangan'])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete('/settings/address-setup/countries/ID')
            ->assertNotFound();

        $this->assertDatabaseMissing('country_regions', ['code' => 'ZZ']);
        $this->assertDatabaseHas('country_regions', ['code' => 'ID']);
    }

    public function test_the_database_refuses_to_drop_a_country_an_address_still_uses(): void
    {
        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'organization',
            'name' => 'PT Contoh',
            'search_name' => Party::searchName('PT Contoh'),
            'status' => 'active',
        ]);
        $location = PartyLocation::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'name' => 'Kantor Pusat',
            'purpose' => 'business',
            'is_primary' => true,
        ]);
        PostalAddress::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $location->id,
            'country_region_code' => 'SG',
            'city' => 'Singapura',
            'formatted' => 'Singapura',
        ]);

        // Penjaganya database, bukan kesopanan kode: seed yang keliru pun tidak dapat
        // menarik negara yang masih dipakai alamat.
        $this->expectException(QueryException::class);
        CountryRegion::query()->where('code', 'SG')->delete();
    }

    public function test_a_member_without_the_right_cannot_write_reference_data(): void
    {
        $anggota = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $anggota->id,
            'system_role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($anggota)
            ->post('/settings/address-setup/provinces', [
                'country_code' => 'ID',
                'code' => '98',
                'name' => 'Provinsi Karangan',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('ref_provinces', ['code' => '98']);
    }

    public function test_someone_without_any_membership_cannot_write_reference_data(): void
    {
        // Master wilayah dipakai seluruh tenant. Sampai 17 September 2026 akun tanpa
        // keanggotaan mana pun dapat menambah dan menghapus isinya.
        $orangLuar = User::factory()->create();

        $this->actingAs($orangLuar)
            ->post('/settings/address-setup/provinces', [
                'country_code' => 'ID',
                'code' => '99',
                'name' => 'Provinsi Karangan',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('ref_provinces', ['code' => '99']);
    }

    public function test_the_country_seeder_does_not_overwrite_indonesian_names(): void
    {
        DB::table('country_regions')->where('code', 'ID')->update(['phone_code' => null]);

        $this->seed(WorldCountriesSeeder::class);

        $indonesia = CountryRegion::query()->find('ID');
        $this->assertSame('Indonesia', $indonesia->name, 'Nama Indonesia tidak boleh tertimpa nama Inggris dari seeder.');
        $this->assertSame('+62', $indonesia->phone_code);
    }
}
