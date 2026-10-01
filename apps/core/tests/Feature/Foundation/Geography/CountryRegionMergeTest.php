<?php

namespace Tests\Feature\Foundation\Geography;

use App\Foundation\AddressBook\Models\Location;
use App\Foundation\AddressBook\Models\Party;
use App\Foundation\AddressBook\Models\PartyLocation;
use App\Foundation\AddressBook\Models\PostalAddress;
use App\Foundation\Geography\Models\AddressHierarchy\Province;
use App\Foundation\Geography\Models\CountryRegion;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\ControlPlane\Models\Client;
use Database\Seeders\WorldCountriesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Satu daftar negara, dan hanya satu.
 *
 * Sampai 29 September 2026 ada dua: `country_regions` yang dirujuk alamat pos, dan
 * `ref_countries` yang dirujuk master wilayah. Test ini menjaga agar keduanya tidak
 * tumbuh kembali menjadi dua, dan agar penghapusan negara tidak dapat menarik alamat
 * atau provinsi ikut hilang.
 */
class CountryRegionMergeTest extends TestCase
{
    use GrantsCoreRoles;
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
        // Membaca master wilayah butuh duty Lihat data referensi, sama seperti layar lain (SEC-22).
        $this->grantDuties(
            TenantMembership::create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $this->admin->id,
                'system_role' => 'owner',
                'status' => 'active',
            ]),
            ['core.reference-data.manage'],
        );
    }

    public function test_ref_countries_no_longer_owns_anything(): void
    {
        // Tabelnya masih ada sebagai bekal mundur satu rilis, tetapi tidak boleh ada
        // satu pun foreign key yang masih menunjuknya.
        $reference = DB::select("
            select tc.table_name
            from information_schema.table_constraints tc
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.constraint_schema = tc.constraint_schema
            where tc.constraint_type = 'FOREIGN KEY' and ccu.table_name = 'ref_countries'
              and tc.table_schema = current_schema()
        ");
        $this->assertSame([], $reference, 'Masih ada tabel yang menunjuk ref_countries.');

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
        $location = Location::create(['tenant_id' => $this->tenant->id, 'name' => 'Kantor Pusat']);
        PartyLocation::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'location_id' => $location->id,
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

    public function test_the_address_master_has_no_write_doors_for_a_tenant(): void
    {
        // Data wilayah dipakai bersama seluruh tenant dan tidak punya pemilik per baris,
        // jadi tidak ada pintu tulisnya sama sekali — bukan sekadar dijaga izin. Bahkan
        // pemilik tenant pun tidak dapat menambahnya.
        $door = [
            ['post', '/settings/address-setup/countries', ['code' => 'ZZ', 'name' => 'Karangan']],
            ['post', '/settings/address-setup/provinces', ['country_code' => 'ID', 'code' => '98', 'name' => 'Karangan']],
            ['post', '/settings/address-setup/villages', ['name' => 'Karangan']],
            ['post', '/settings/address-setup/postal-codes', ['postal_code' => '99999']],
            ['post', '/settings/address-setup/parameters', ['country_code' => 'ID']],
        ];

        foreach ($door as [$method, $address, $values]) {
            $this->actingAs($this->admin)->{$method}($address, $values)->assertNotFound();
        }

        $this->assertDatabaseMissing('country_regions', ['code' => 'ZZ']);
        $this->assertDatabaseMissing('ref_provinces', ['code' => '98']);
    }

    public function test_the_address_master_is_still_readable(): void
    {
        // Mengunci tulisannya tidak boleh ikut menutup bacaannya: layar dan pencarian
        // alamat tetap membutuhkannya.
        $this->actingAs($this->admin)
            ->get('/settings/address-setup?section=countries')
            ->assertOk();
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
