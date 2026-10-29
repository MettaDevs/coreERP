<?php

namespace Tests\Feature\Foundation\Vendor;

use App\Foundation\Vendor\Models\Vendor;
use App\Platform\AddressBook\Events\PartyTaxNumberChanged;
use App\Platform\AddressBook\Models\Party;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\VendorDirectory;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VendorSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    private string $companyId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);
        $this->owner = $this->createOwner('search-owner@example.test', 'Search tenant');
        $this->membership = $this->owner->activeMembership();
        $this->companyId = $this->createCompany('Search company', 'SEARCH');
    }

    public function test_words_match_prefixes_in_any_order_and_require_all_terms(): void
    {
        $target = $this->createVendor(['name' => 'Surya Jaya Abadi']);
        $this->createVendor(['name' => 'Surya Kencana']);
        $this->createVendor(['name' => 'Jaya Utama']);

        $this->assertSame([$target->id], $this->listIds('sur ja'));
        $this->assertSame([$target->id], $this->listIds('JAYA SURYA'));
        $this->assertSame([], $this->listIds('urya'));
        $this->assertSame([], $this->listIds('sur hilang'));
    }

    public function test_quoted_phrases_are_ordered_complete_words_in_one_field(): void
    {
        $target = $this->createVendor(['name' => 'Surya Jaya Abadi']);
        $this->createVendor(['name' => 'Surya Sentosa Jaya']);
        $crossField = $this->createVendor(['name' => 'Jaya Abadi', 'number' => 'SURYA']);

        $this->assertSame([$target->id], $this->listIds('"surya jaya"'));
        $this->assertSame([], $this->listIds('"jaya surya"'));
        $this->assertSame([], $this->listIds('"surya ja"'));
        $this->assertContains($crossField->id, $this->listIds('surya jaya'));
    }

    public function test_numbers_and_tax_numbers_can_be_found_with_or_without_formatting(): void
    {
        $target = $this->createVendor([
            'name' => 'Identifier account',
            'number' => 'VND-000001',
            'tax_number' => '12.345.678.9-012.345',
        ]);
        $this->createVendor(['name' => 'Other identifier', 'number' => 'VND-000002']);

        foreach (['VND-000001', 'vnd000001', 'VND.000001', 'VND 000001', '000001', '123456789012345', '12.345.678.9-012.345', '12 345 678 9 012 345'] as $query) {
            $this->assertSame([$target->id], $this->listIds($query), $query);
        }
    }

    public function test_apostrophes_and_non_ascii_name_words_are_searchable(): void
    {
        $target = $this->createVendor(['name' => "D'Áyu Perkasa"]);
        $this->createVendor(['name' => 'Daya Persada']);

        $this->assertSame([$target->id], $this->listIds('áy per'));
        $this->assertSame([$target->id], $this->listIds('perk ÁYU'));
    }

    public function test_search_ignores_case_but_preserves_accents(): void
    {
        $accented = $this->createVendor(['name' => 'Café Sinar']);
        $plain = $this->createVendor(['name' => 'Cafe Sinar']);

        $this->assertSame([$plain->id], $this->listIds('CAFE sin'));
        $this->assertSame([$accented->id], $this->listIds('CAFÉ SIN'));
    }

    public function test_incomplete_quotes_and_literal_operator_text_do_not_break_the_query(): void
    {
        $target = $this->createVendor(['name' => 'Surya Jaya Abadi']);
        $this->createVendor(['name' => 'Unrelated account']);

        $this->assertSame([$target->id], $this->listIds('"surya ja'));
        foreach (['%', '_', '|', '&', '@', '"', "' OR 1=1 --"] as $query) {
            $this->assertSame([], $this->listIds($query), $query);
        }
    }

    public function test_tenant_company_status_and_deleted_party_boundaries_remain_applied(): void
    {
        $target = $this->createVendor(['name' => 'Surya Jaya', 'number' => 'SCOPE-001']);
        $inactive = $this->createVendor(['name' => 'Surya Jaya', 'number' => 'SCOPE-002', 'status' => Vendor::INACTIVE]);
        $secondCompany = $this->createCompany('Second search company', 'SECOND');
        $second = $this->createVendor(['name' => 'Surya Jaya', 'number' => 'SCOPE-003', 'company_id' => $secondCompany]);
        $deleted = $this->createVendor(['name' => 'Surya Jaya', 'number' => 'SURYA-DELETED']);
        $deleted->party()->firstOrFail()->delete();

        $other = $this->createOwner('foreign-search@example.test', 'Foreign search tenant');
        $foreignCompany = $this->createCompany('Foreign search company', 'FOREIGN', $other);
        $foreign = $this->createVendor([
            'name' => 'Surya Jaya', 'number' => 'FOREIGN-001',
            'tenant_id' => $other->activeMembership()->tenant_id, 'company_id' => $foreignCompany,
        ]);

        $this->assertEqualsCanonicalizing([$target->id, $inactive->id, $second->id], $this->listIds('surya ja'));
        $this->assertSame([$target->id], $this->listIds('surya ja', ['legal_entity' => $this->companyId, 'status' => Vendor::ACTIVE]));
        $this->assertSame([], $this->listIds('SURYA-DELETED'));
        $this->assertNotContains($foreign->id, $this->listIds('surya'));
    }

    public function test_shared_party_renames_and_tax_changes_are_immediately_searchable(): void
    {
        $first = $this->createVendor(['name' => 'Surya Jaya', 'number' => 'SHARED-001', 'tax_number' => '11.111.111.1-111.111']);
        $secondCompany = $this->createCompany('Shared second company', 'SHARED2');
        $second = $this->createVendor(['party' => $first->party, 'number' => 'SHARED-002', 'company_id' => $secondCompany]);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->listIds('sur ja'));

        $first->party->forceFill(['name' => 'Cahaya Baru', 'search_name' => 'cahaya baru'])->save();
        $this->assertSame([], $this->listIds('sur ja'));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->listIds('baru cah'));

        $first->party->forceFill(['tax_number' => '22.222.222.2-222.222'])->save();
        event(new PartyTaxNumberChanged($this->membership->tenant_id, $first->party_id, '22.222.222.2-222.222'));
        $this->assertSame([], $this->listIds('111111111111111'));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->listIds('222222222222222'));
    }

    public function test_batches_keep_number_order_without_duplicates(): void
    {
        $expected = [];
        for ($index = 1; $index <= 52; $index++) {
            $expected[] = $this->createVendor([
                'name' => 'Search supplier batch '.$index,
                'number' => sprintf('PAGED-%03d', $index),
            ])->id;
        }

        $first = $this->listIds('batch sup');
        $second = $this->listIds('batch sup', ['page' => 2]);
        $this->assertCount(50, $first);
        $this->assertCount(2, $second);
        $actual = [...$first, ...$second];
        $this->assertSame($expected, $actual);
        $this->assertCount(52, array_unique($actual));
    }

    public function test_lookup_and_module_directory_share_matching_and_keep_their_existing_scope(): void
    {
        $target = $this->createVendor(['name' => 'Surya Jaya', 'number' => 'LOOKUP-001', 'tax_number' => '33.333.333.3-333.333']);
        $inactive = $this->createVendor(['name' => 'Surya Jaya', 'number' => 'LOOKUP-002', 'status' => Vendor::INACTIVE]);
        $this->createVendor(['name' => 'Surya Utama', 'number' => 'LOOKUP-003']);
        $company = $this->createCompany('Lookup second company', 'LOOKUP2');
        $this->createVendor(['name' => 'Surya Jaya', 'number' => 'LOOKUP-004', 'company_id' => $company]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/vendors/lookup?'.http_build_query([
            'legal_entity_id' => $this->companyId, 'q' => 'ja sur',
        ]))->assertOk();
        $this->assertSame([$target->id, $inactive->id], array_column($response->json('data'), 'id'));

        $directory = app(VendorDirectory::class);
        $this->assertSame([$target->id], array_column($directory->active($this->membership->tenant_id, $this->companyId, 'ja sur'), 'id'));
        $page = $directory->lookup($this->membership->tenant_id, $this->companyId, ['q' => '333333333333333']);
        $this->assertSame([$target->id], collect($page['data'])->pluck('id')->all());
    }

    /** @param array<string, mixed> $attributes */
    private function createVendor(array $attributes = []): Vendor
    {
        $tenantId = $attributes['tenant_id'] ?? $this->membership->tenant_id;
        $party = $attributes['party'] ?? Party::query()->create([
            'tenant_id' => $tenantId,
            'type' => 'organization',
            'name' => $attributes['name'] ?? 'Search fixture',
            'search_name' => mb_strtolower($attributes['name'] ?? 'Search fixture'),
            'tax_number' => $attributes['tax_number'] ?? null,
            'status' => 'active',
        ]);

        return Vendor::query()->create([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $attributes['company_id'] ?? $this->companyId,
            'party_id' => $party->id,
            'number' => $attributes['number'] ?? sprintf('TEST-%04d', ++$this->sequence),
            'tax_number' => $attributes['tax_number'] ?? $party->tax_number,
            'status' => $attributes['status'] ?? Vendor::ACTIVE,
            'created_by_user_id' => $this->owner->id,
        ]);
    }

    /** @param array<string, string|int> $filters
     * @return list<string>
     */
    private function listIds(string $query, array $filters = []): array
    {
        $ids = [];
        $this->actingAs($this->owner)->get('/settings/vendors?'.http_build_query(['q' => $query, ...$filters]))
            ->assertOk()->assertInertia(function (AssertableInertia $page) use (&$ids): void {
                $page->component('foundation/vendor/vendors')
                    ->where('vendors.data', function ($rows) use (&$ids): bool {
                        $ids = collect($rows)->pluck('id')->all();

                        return true;
                    });
            });

        return $ids;
    }

    private function createOwner(string $email, string $name): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$name, 'business_name' => $name,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function createCompany(string $name, string $code, ?User $owner = null): string
    {
        $this->actingAs($owner ?? $this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => $name, 'company_code' => $code, 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();

        return (string) DB::table('organizations')->where('name', $name)->value('id');
    }
}
