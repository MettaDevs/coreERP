<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\SharedDimensionRegistry;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Label dimensi bersama: hanya dari tenant yang ditanya, termasuk record yang sudah tidak aktif (data lama
 * tetap bernama), hanya untuk jenis record yang benar, dan label nama orang hanya bagi yang berhak membaca
 * data pribadi. Resolver vendor dan mata uang didaftarkan fitur Foundation pemiliknya, bukan Platform.
 */
class SharedDimensionRegistryTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->tenant('Tenant label A');
        $this->tenantB = $this->tenant('Tenant label B');
    }

    public function test_organization_labels_are_read_from_the_asking_tenant_and_the_right_classification(): void
    {
        $legalEntity = $this->organization($this->tenantA, 'legal_entity', 'PT Label', 'active');
        $closedEntity = $this->organization($this->tenantA, 'legal_entity', 'PT Lama', 'inactive');
        $unit = $this->organization($this->tenantA, 'operating_unit', 'Unit Gudang', 'active');
        $otherTenant = $this->organization($this->tenantB, 'legal_entity', 'PT Tenant Lain', 'active');
        $ids = [$legalEntity, $closedEntity, $unit, $otherTenant, 'bukan-id'];

        $this->assertSame(
            $this->sorted([$legalEntity => 'PT Label', $closedEntity => 'PT Lama']),
            $this->sorted($this->labels(SharedDimension::LegalEntity, $ids)),
        );
        $this->assertSame([$unit => 'Unit Gudang'], $this->labels(SharedDimension::OperatingUnit, $ids));
        // Nama organisasi bukan data pribadi: tetap berlabel tanpa hak data pribadi.
        $this->assertSame([$unit => 'Unit Gudang'], $this->labels(SharedDimension::OperatingUnit, $ids, mayUsePersonalData: false));
    }

    public function test_user_labels_are_member_names_and_only_for_principals_allowed_personal_data(): void
    {
        $member = $this->member($this->tenantA, 'Ani Anggota', 'active');
        $former = $this->member($this->tenantA, 'Budi Mantan', 'inactive');
        $stranger = $this->member($this->tenantB, 'Citra Tenant Lain', 'active');
        $ids = [$member, $former, $stranger, '01J9ZBUKANANGKA'];

        $this->assertSame(
            $this->sorted([$member => 'Ani Anggota', $former => 'Budi Mantan']),
            $this->sorted($this->labels(SharedDimension::User, $ids)),
        );
        $this->assertSame([], $this->labels(SharedDimension::User, $ids, mayUsePersonalData: false), 'Nama orang adalah data pribadi; tanpa hak hanya id yang tampil.');
    }

    public function test_vendor_and_currency_labels_come_from_their_foundation_features(): void
    {
        $vendor = $this->vendor($this->tenantA, 'CV Pemasok');
        $foreignVendor = $this->vendor($this->tenantB, 'CV Tenant Lain');

        $this->assertSame([$vendor => 'CV Pemasok'], $this->labels(SharedDimension::Vendor, [$vendor, $foreignVendor]));
        $this->assertSame([], $this->labels(SharedDimension::Vendor, [$vendor], mayUsePersonalData: false), 'Party vendor dapat berupa orang; namanya berkelas data pribadi di buku alamat.');
        $this->assertSame(['IDR' => 'IDR', 'USD' => 'USD'], $this->labels(SharedDimension::Currency, ['IDR', 'usd', 'USD', ''], mayUsePersonalData: false));
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function labels(SharedDimension $dimension, array $ids, bool $mayUsePersonalData = true): array
    {
        return app(SharedDimensionRegistry::class)->labels($dimension, $this->tenantA, $ids, $mayUsePersonalData);
    }

    /**
     * @param  array<int|string, string>  $labels
     * @return array<int|string, string>
     */
    private function sorted(array $labels): array
    {
        ksort($labels);

        return $labels;
    }

    private function tenant(string $name): string
    {
        $client = (string) Str::ulid();
        $tenant = (string) Str::ulid();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
        DB::table('clients')->insert(['id' => $client, 'legal_name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenant, 'client_id' => $client, 'name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $tenant;
    }

    private function organization(string $tenantId, string $classification, string $name, string $status): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'classification' => $classification,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($classification === 'legal_entity') {
            DB::table('legal_entities')->insert([
                'organization_id' => $id, 'company_code' => Str::upper(Str::random(4)), 'country_code' => 'ID',
                'timezone' => 'Asia/Jakarta', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function member(string $tenantId, string $name, string $status): string
    {
        $user = User::factory()->create(['name' => $name]);
        TenantMembership::query()->create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'status' => $status]);

        return (string) $user->id;
    }

    private function vendor(string $tenantId, string $name): string
    {
        $party = (string) Str::ulid();
        $vendor = (string) Str::ulid();
        DB::table('parties')->insert([
            'id' => $party, 'tenant_id' => $tenantId, 'type' => 'organization', 'name' => $name,
            'search_name' => Str::upper($name), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vendors')->insert([
            'id' => $vendor, 'tenant_id' => $tenantId, 'legal_entity_id' => $this->organization($tenantId, 'legal_entity', 'PT '.$name, 'active'),
            'party_id' => $party, 'number' => 'V-'.Str::upper(Str::random(5)), 'status' => 'inactive',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $vendor;
    }
}
