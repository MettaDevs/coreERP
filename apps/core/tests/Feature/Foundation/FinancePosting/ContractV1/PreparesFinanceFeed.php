<?php

namespace Tests\Feature\Foundation\FinancePosting\ContractV1;

use App\Foundation\FinancePosting\Models\FinancePostingSetting;
use App\Foundation\FinancePosting\Models\FinanceReferenceAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\PostingFeed;
use App\Platform\Organization\Models\Organization;
use App\Platform\Organization\Models\OrganizationHierarchyVersion;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Keadaan awal bersama untuk test kontrak feed posting finance v1.
 *
 * Satu tenant, satu entitas legal `META` dengan feed aktif sejak 1 September 2026, satu business
 * unit `KLN-A` yang membawahi department `POLI-UMUM`, empat akun, dan satu vendor. Jam dibekukan
 * pada 28 September 2026 16:50:04 UTC, supaya `published_at`, `acknowledged_at`, dan timestamp
 * signature dapat dibandingkan dengan snapshot.
 *
 * Snapshot di `tests/Fixtures/kontrak-feed-finance-v1/` adalah bentuk yang dilihat klien
 * integrasi hari ini. Id yang berubah setiap putaran (ULID entitas legal, unit, vendor, klien)
 * diganti penanda seperti `{legal_entity}` sebelum dibandingkan. Snapshot sengaja tidak diperbarui
 * otomatis: menulis ulangnya hanya lewat `KONTRAK_FEED_SNAPSHOT=tulis`, dan perubahan berkas
 * fixture di pull request adalah tanda bahwa kontrak v1 berubah.
 */
trait PreparesFinanceFeed
{
    private User $owner;

    private TenantMembership $membership;

    private Organization $legalEntity;

    private Organization $clinic;

    private Organization $department;

    /** @var array<string, string> */
    private array $accounts = [];

    private string $vendorId;

    protected function prepareFinanceFeed(): void
    {
        $this->seed(AppCatalogSeeder::class);
        // On-prem kecuali test yang sengaja menguji SaaS; `.env` pengembang tidak boleh memengaruhi hasil.
        config(['coreerp.base_domain' => null]);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-09-28T16:50:04Z'));

        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner PT Metta', 'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'], 'email' => 'owner@metta.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();

        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'legal_entity', 'name' => 'PT Metta Sehat', 'company_code' => 'META', 'country_code' => 'ID',
        ])->assertSessionHasNoErrors();
        $this->legalEntity = Organization::query()->where('name', 'PT Metta Sehat')->firstOrFail();
        $this->clinic = $this->operatingUnit('Klinik A', 'business_unit', 'KLN-A');
        $this->department = $this->operatingUnit('Poli Umum', 'department', 'POLI-UMUM');
        $this->publishManagementHierarchy();

        foreach ([
            'asset' => ['1452', '1-2300', 'Aset Tetap - Kendaraan', 'balance_sheet'],
            'payable' => ['2110', '2-1100', 'Hutang Usaha', 'balance_sheet'],
            'accumulated' => ['1453', '1-2390', 'Akumulasi Penyusutan - Kendaraan', 'balance_sheet'],
            'expense' => ['6510', '6-5100', 'Beban Penyusutan Kendaraan', 'profit_loss'],
        ] as $key => [$externalId, $code, $name, $type]) {
            $this->accounts[$key] = FinanceReferenceAccount::query()->create([
                'tenant_id' => $this->membership->tenant_id, 'legal_entity_id' => null, 'external_id' => $externalId,
                'code' => $code, 'name' => $name, 'type' => $type, 'active' => true,
            ])->id;
        }

        FinancePostingSetting::query()->create([
            'legal_entity_id' => $this->legalEntity->id, 'tenant_id' => $this->membership->tenant_id,
            'enabled' => true, 'cutover_date' => '2026-09-01',
        ]);
        $this->vendorId = (string) $this->actingAs($this->owner)->postJson('/api/v1/vendors', [
            'legal_entity_id' => $this->legalEntity->id, 'party_name' => 'PT Karoseri Sehat',
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    private function publish(array $input): array
    {
        return DB::transaction(fn (): array => app(PostingFeed::class)->publish($input));
    }

    /**
     * Penerimaan aset dengan vendor dan mode `direct_payable`: dua baris neraca.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function acquisition(string $postingId = 'AST-ACQ-0001', string $date = '2026-09-28', string $amount = '500000000.00', array $overrides = []): array
    {
        return [
            'tenant_id' => $this->membership->tenant_id,
            'posting_id' => $postingId,
            'posting_type' => 'asset.acquisition',
            'legal_entity_id' => $this->legalEntity->id,
            'currency_code' => 'IDR',
            'posting_date' => $date,
            'document_date' => $date,
            'occurred_at' => $date.'T23:50:00+07:00',
            'settlement_mode' => 'direct_payable',
            'requires_vendor' => true,
            'vendor_id' => $this->vendorId,
            'source_document' => [
                'module' => 'management-aset', 'type' => 'penerimaan-aset', 'number' => 'PNA-2026-09-0007',
                'description' => 'Penerimaan ambulans', 'url' => '/management-aset/penerimaan/1',
            ],
            'lines' => [
                ['account_id' => $this->accounts['asset'], 'debit' => $amount, 'credit' => '0', 'description' => 'PNA-2026-09-0007 · Kendaraan', 'org_unit_id' => $this->department->id],
                ['account_id' => $this->accounts['payable'], 'debit' => '0', 'credit' => $amount, 'description' => 'PT Karoseri Sehat / PNA-2026-09-0007', 'org_unit_id' => $this->department->id],
            ],
            'details' => ['assets' => [['asset_code' => 'KEND-0012', 'acquisition_value' => $amount]]],
            ...$overrides,
        ];
    }

    /**
     * Penyusutan tanpa vendor dan tanpa `details`: akun laba rugi membawa dua dimensi.
     *
     * @return array<string, mixed>
     */
    private function depreciation(string $postingId = 'AST-DEP-2026-09', string $date = '2026-09-30'): array
    {
        return [
            'tenant_id' => $this->membership->tenant_id,
            'posting_id' => $postingId,
            'posting_type' => 'asset.depreciation',
            'legal_entity_id' => $this->legalEntity->id,
            'currency_code' => 'IDR',
            'posting_date' => $date,
            'document_date' => $date,
            'occurred_at' => '2026-10-01T08:00:00+07:00',
            'source_document' => ['module' => 'management-aset', 'type' => 'penyusutan', 'number' => null, 'description' => 'Penyusutan buku KOMERSIAL'],
            'lines' => [
                ['account_id' => $this->accounts['expense'], 'debit' => '1250000.00', 'credit' => '0', 'org_unit_id' => $this->department->id],
                ['account_id' => $this->accounts['accumulated'], 'debit' => '0', 'credit' => '1250000.00', 'org_unit_id' => $this->department->id],
            ],
        ];
    }

    /**
     * Klien mode pull lewat layar admin, seperti yang diterima tim finance.
     *
     * @param  list<string>  $scopes
     * @param  list<string>  $prefixes
     * @param  list<string>  $allowedIps
     */
    private function pullToken(array $scopes = ['finance-postings.read', 'finance-postings.ack'], array $prefixes = ['asset.'], array $allowedIps = [], string $name = ''): string
    {
        return (string) $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => $name !== '' ? $name : 'Finance pull '.str()->random(6), 'delivery_mode' => 'pull', 'push_url' => null,
            'scopes' => $scopes, 'posting_type_prefixes' => $prefixes, 'allowed_ips' => $allowedIps,
        ])->assertCreated()->json('token');
    }

    /**
     * Klien mode push. Mengembalikan id klien dan signing secret-nya.
     *
     * @param  list<string>  $prefixes
     * @return array{id: string, secret: string}
     */
    private function pushClient(string $url = 'https://finance.example.test/hook', array $prefixes = ['asset.'], string $name = ''): array
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/integration-clients', [
            'name' => $name !== '' ? $name : 'Finance push '.str()->random(6), 'delivery_mode' => 'push', 'push_url' => $url,
            'scopes' => ['finance-postings.read', 'finance-postings.ack'], 'posting_type_prefixes' => $prefixes, 'allowed_ips' => [],
        ])->assertCreated();

        return ['id' => (string) $response->json('data.id'), 'secret' => (string) $response->json('signing_secret')];
    }

    /** @param  array<string, mixed>  $query */
    private function pull(string $token, array $query = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/internal/v1/finance-postings'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @param  array<string, mixed>  $body */
    private function ack(string $token, string $postingId, array $body): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/internal/v1/finance-postings/'.$postingId.'/ack', $body);
    }

    /**
     * Penanda untuk id yang berubah setiap putaran.
     *
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        return [
            $this->legalEntity->id => '{legal_entity}',
            $this->clinic->id => '{business_unit}',
            $this->department->id => '{department}',
            $this->vendorId => '{vendor}',
        ];
    }

    /**
     * JSON dalam bentuk baku untuk dibandingkan: objek tetap objek (`{}` tidak menjadi `[]`), urutan
     * kunci dan tipe nilai dipertahankan, dan id diganti penanda.
     */
    private function canonicalJson(string $raw): string
    {
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

        return strtr(
            json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->placeholders(),
        )."\n";
    }

    private function assertMatchesSnapshot(string $name, string $actual): void
    {
        $path = base_path('tests/Fixtures/kontrak-feed-finance-v1/'.$name);
        if (getenv('KONTRAK_FEED_SNAPSHOT') === 'tulis') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $actual);
        }

        $this->assertFileExists($path, 'Snapshot kontrak feed finance v1 belum ada: '.$name);
        $this->assertSame(
            (string) file_get_contents($path),
            $actual,
            'Bentuk yang dilihat klien integrasi berubah dari snapshot kontrak v1 '.$name.'. '
            .'Bila perubahan ini disengaja, kontraknya berubah: terbitkan versi baru, jangan menimpa snapshot.',
        );
    }

    private function operatingUnit(string $name, string $type, string $number): Organization
    {
        $this->actingAs($this->owner)->post('/settings/organization/organizations', [
            'classification' => 'operating_unit', 'name' => $name,
            'operating_unit_type' => $type, 'operating_unit_number' => $number,
        ])->assertSessionHasNoErrors();

        return Organization::query()->where('name', $name)->latest('created_at')->firstOrFail();
    }

    private function publishManagementHierarchy(): void
    {
        $this->actingAs($this->owner)->post('/settings/organization/hierarchies', [
            'name' => 'Struktur manajemen', 'purpose_codes' => ['management'],
            'root_organization_id' => $this->legalEntity->id, 'effective_from' => '2026-01-01',
        ])->assertSessionHasNoErrors();
        $version = OrganizationHierarchyVersion::query()
            ->whereHas('hierarchy', fn ($query) => $query->where('name', 'Struktur manajemen'))
            ->firstOrFail();
        foreach ([[$this->clinic, $this->legalEntity], [$this->department, $this->clinic]] as [$child, $parent]) {
            $this->post("/settings/organization/hierarchy-versions/{$version->id}/placements", [
                'version' => $version->hierarchy()->value('version'),
                'organization_id' => $child->id, 'parent_organization_id' => $parent->id,
            ])->assertSessionHasNoErrors();
        }
        $this->post("/settings/organization/hierarchy-versions/{$version->id}/publish", ['version' => $version->hierarchy()->value('version')])->assertSessionHasNoErrors();
    }
}
