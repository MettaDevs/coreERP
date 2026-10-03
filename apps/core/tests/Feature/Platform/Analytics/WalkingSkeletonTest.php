<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\TenantProvisioned;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Kerangka berjalan engine analitik (area 0, `docs/todo/analitik/todo-fase-1.md`): satu dataset module
 * yang sungguhan — register aset — dibaca Core lewat `POST /api/v1/analytics/query`, dengan rantai izin,
 * kebijakan data, dan pemasangan module yang sungguhan juga.
 *
 * Yang dibuktikan di sini adalah setiap lapis tersambung dan setiap penangkal bekerja: tenant tidak
 * bercampur, permission baca resource menjaga dataset, hibah kebijakan data menyempitkan baris persis
 * seperti layar module, uang tidak dijumlah lintas mata uang, dan eksekusi baca-saja tidak meninggalkan
 * jejak di transaksi pemanggilnya. Setiap test pernah dilihat merah dengan merusak penangkalnya; caranya
 * ditulis di pull request area 0.
 *
 * Core tidak menulis ke tabel aset di mana pun pada jalur ini. Test ini menyisipkan aset langsung ke tabel
 * module hanya sebagai data awal, seperti `ListExportTest`.
 */
class WalkingSkeletonTest extends TestCase
{
    use GrantsCoreRoles, RefreshDatabase;

    private const DATASET = 'management-aset.asset-register';

    private const POLICY = 'management-aset.asset-responsibility';

    private User $owner;

    private TenantMembership $membership;

    private string $legalEntity;

    private string $unitA;

    private string $unitB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['analytics.enabled' => true]);
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->artisan('app:register-manifest', ['module' => 'management-aset'])->assertSuccessful();
        Event::fake([TenantProvisioned::class]);

        $this->owner = $this->business('Tenant analitik A', 'owner-a@analitik.test');
        $membership = $this->owner->activeMembership();
        $this->assertNotNull($membership);
        $this->membership = $membership;

        $tenant = (string) $this->membership->tenant_id;
        $this->legalEntity = $this->organization($tenant, 'legal_entity', 'CV Analitik');
        $this->unitA = $this->organization($tenant, 'operating_unit', 'Unit A');
        $this->unitB = $this->organization($tenant, 'operating_unit', 'Unit B');
        $masters = $this->masters($tenant);

        // Unit dimensi keuangan sengaja berbeda dari unit penanggung jawab, supaya kebijakan yang memakai
        // kolom yang salah terlihat sebagai angka yang salah.
        $this->asset($tenant, $masters, 'AST-A1', $this->unitA, $this->unitA, '100000000', 'IDR');
        $this->asset($tenant, $masters, 'AST-A2', $this->unitA, $this->unitB, '50000000.50', 'IDR');
        $this->asset($tenant, $masters, 'AST-A3', $this->unitA, $this->unitB, '10000000', 'IDR', 'disposed');
        $this->asset($tenant, $masters, 'AST-B1', $this->unitB, $this->unitA, '200000000', 'IDR');
        $this->asset($tenant, $masters, 'AST-B2', $this->unitB, $this->unitB, '1500.25', 'USD', 'decommissioned');
    }

    public function test_counts_assets_and_sums_acquisition_value_per_status_with_labels(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [
            'dataset' => self::DATASET,
            'dimensions' => ['lifecycle_state'],
            'measures' => ['count', 'acquisition_value'],
        ])->assertOk();

        $response->assertJsonPath('columns', [
            ['key' => 'lifecycle_state', 'kind' => 'dimension', 'caption' => 'Status aset', 'type' => 'option', 'label_key' => 'lifecycle_state__label'],
            ['key' => 'currency_code', 'kind' => 'dimension', 'caption' => 'Mata uang', 'type' => 'text', 'implicit' => true],
            ['key' => 'count', 'kind' => 'measure', 'caption' => 'Jumlah aset', 'type' => 'number', 'format' => 'number'],
            ['key' => 'acquisition_value', 'kind' => 'measure', 'caption' => 'Nilai perolehan', 'type' => 'number', 'format' => 'money', 'currency_key' => 'currency_code'],
        ]);
        // Urut jumlah terbanyak; seri diputus status lalu mata uang. Uang dikirim sebagai string.
        $response->assertJsonPath('rows', [
            ['lifecycle_state' => 'received', 'lifecycle_state__label' => 'Diterima', 'currency_code' => 'IDR', 'count' => 3, 'acquisition_value' => '350000000.50'],
            ['lifecycle_state' => 'decommissioned', 'lifecycle_state__label' => 'Didekomisioning', 'currency_code' => 'USD', 'count' => 1, 'acquisition_value' => '1500.25'],
            ['lifecycle_state' => 'disposed', 'lifecycle_state__label' => 'Dilepas', 'currency_code' => 'IDR', 'count' => 1, 'acquisition_value' => '10000000.00'],
        ]);
        $response->assertJsonPath('totals', [])
            ->assertJsonPath('meta.dataset', self::DATASET)
            ->assertJsonPath('meta.dataset_version', 1)
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonPath('meta.row_limit', 5000)
            ->assertJsonPath('meta.cached', false)
            // Zona dari layanan yang sama dengan laporan: pengguna tanpa zona sendiri mengikuti entitas legal aktif.
            ->assertJsonPath('meta.timezone', 'Asia/Makassar');
        $this->assertStringEndsWith('+08:00', (string) $response->json('meta.generated_at'));
        $this->assertStringStartsWith('sha256:', (string) $response->json('meta.query_hash'));
    }

    public function test_two_tenants_never_mix(): void
    {
        $ownerB = $this->business('Tenant analitik B', 'owner-b@analitik.test');
        $tenantB = (string) $ownerB->activeMembership()?->tenant_id;
        $this->asset($tenantB, $this->masters($tenantB), 'AST-T1', $this->organization($tenantB, 'operating_unit', 'Unit T'), null, '999000000', 'IDR', 'received', $this->organization($tenantB, 'legal_entity', 'PT Tenant B'));

        $query = ['dataset' => self::DATASET, 'measures' => ['count', 'acquisition_value']];

        $this->actingAs($ownerB)->postJson('/api/v1/analytics/query', $query)->assertOk()
            ->assertJsonPath('rows', [['currency_code' => 'IDR', 'count' => 1, 'acquisition_value' => '999000000.00']]);
        $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', $query)->assertOk()
            ->assertJsonPath('rows', [
                ['currency_code' => 'IDR', 'count' => 4, 'acquisition_value' => '360000000.50'],
                ['currency_code' => 'USD', 'count' => 1, 'acquisition_value' => '1500.25'],
            ]);

        // Tenant tidak pernah datang dari pemanggil: `tenant_id` bukan kolom yang dapat disaring.
        $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [...$query, 'filters' => ['tenant_id' => [$tenantB]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'analytics.field_unknown')->assertJsonPath('error.field', 'filters.tenant_id');
    }

    public function test_member_without_asset_read_permission_is_forbidden(): void
    {
        // Memegang permission module aset, tetapi bukan permission baca register aset.
        $workOrdersOnly = $this->member(['management-aset.pemeliharaan-aset.manage']);

        $this->actingAs($workOrdersOnly)->postJson('/api/v1/analytics/query', ['dataset' => self::DATASET, 'measures' => ['count']])
            ->assertForbidden()
            ->assertExactJson(['error' => ['code' => 'analytics.dataset_forbidden', 'message' => 'Anda tidak punya akses ke data ini.', 'field' => 'dataset']]);
    }

    public function test_data_policy_grant_narrows_rows_exactly_like_the_module_list(): void
    {
        $query = ['dataset' => self::DATASET, 'measures' => ['count', 'acquisition_value']];

        // Hibah unit A saja: hanya aset yang unit penanggung jawabnya A, bukan unit dimensi keuangannya.
        $unitAOnly = $this->member(['management-aset.aset.manage'], [[$this->legalEntity, $this->unitA]]);
        $this->actingAs($unitAOnly)->postJson('/api/v1/analytics/query', $query)->assertOk()
            ->assertJsonPath('rows', [['currency_code' => 'IDR', 'count' => 3, 'acquisition_value' => '160000000.50']]);

        // Hak membaca ada, hibah tidak ada: nol, bukan seluruh aset tenant.
        $withoutGrant = $this->member(['management-aset.aset.manage']);
        $this->actingAs($withoutGrant)->postJson('/api/v1/analytics/query', ['dataset' => self::DATASET, 'measures' => ['count']])->assertOk()
            ->assertJsonPath('rows', [['count' => 0]]);
    }

    public function test_money_is_never_summed_across_currencies(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', ['dataset' => self::DATASET, 'measures' => ['acquisition_value']])
            ->assertOk()
            ->assertJsonPath('columns.0', ['key' => 'currency_code', 'kind' => 'dimension', 'caption' => 'Mata uang', 'type' => 'text', 'implicit' => true])
            ->assertJsonPath('rows', [
                ['currency_code' => 'IDR', 'acquisition_value' => '360000000.50'],
                ['currency_code' => 'USD', 'acquisition_value' => '1500.25'],
            ]);
    }

    public function test_filters_narrow_and_unknown_or_unreadable_parts_are_rejected_with_their_path(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [
            'dataset' => self::DATASET, 'measures' => ['count'], 'filters' => ['lifecycle_state' => ['received', 'disposed'], 'currency_code' => 'IDR'],
        ])->assertOk()->assertJsonPath('rows', [['count' => 4]]);

        $cases = [
            [['filters' => ['lifecycle_state' => ['hilang']]], 'analytics.invalid_filter', 'filters.lifecycle_state'],
            [['filters' => ['nama' => '*laptop*']], 'analytics.field_unknown', 'filters.nama'],
            [['dimensions' => ['lifecycle_state', 'kode']], 'analytics.field_unknown', 'dimensions.1'],
            [['measures' => ['count', 'nilai_buku']], 'analytics.field_unknown', 'measures.1'],
            [['dimensions' => [['field' => 'acquired_on', 'granularity' => 'month']]], 'analytics.invalid_query', 'dimensions.0'],
            [['time_range' => ['range' => '@this_month']], 'analytics.invalid_query', 'time_range'],
            [['limit' => 5001], 'analytics.limit_exceeded', 'limit'],
        ];
        foreach ($cases as [$part, $code, $field]) {
            $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [...['dataset' => self::DATASET, 'measures' => ['count']], ...$part])
                ->assertStatus(422)->assertJsonPath('error.code', $code)->assertJsonPath('error.field', $field);
        }
    }

    public function test_unknown_dataset_and_module_not_installed_for_the_tenant_are_not_found(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', ['dataset' => 'management-aset.tidak-ada', 'measures' => ['count']])
            ->assertNotFound()->assertJsonPath('error.code', 'analytics.dataset_unknown');

        // Permission dan hibahnya masih ada, tetapi module-nya tidak lagi terpasang untuk tenant ini.
        DB::table('core_module_installations')->where('tenant_id', $this->membership->tenant_id)->where('module_id', 'management-aset')->update(['status' => 'disabled']);
        $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', ['dataset' => self::DATASET, 'measures' => ['count']])
            ->assertNotFound()->assertJsonPath('error.code', 'analytics.dataset_unknown');
    }

    public function test_executor_rolls_back_to_its_savepoint_so_the_callers_transaction_can_still_write(): void
    {
        $dataset = app(DatasetRegistry::class)->find(self::DATASET);
        $this->assertNotNull($dataset);
        $principal = UserPrincipal::fromMembership($this->membership, 'UTC');
        $query = new AnalyticsQuery(self::DATASET, [], ['count'], [], null, [], null, false, false);
        $level = DB::transactionLevel();

        $result = app(TenantRunner::class)->runFor($principal->tenantId(), static fn (): array => app(QueryExecutor::class)
            ->run(app(QueryCompiler::class)->compile($dataset, $query, $principal), 3000));

        $this->assertSame(5, $result['rows'][0]->m0 ?? null);
        $this->assertSame($level, DB::transactionLevel());
        // READ ONLY dan statement_timeout ikut batal bersama savepoint-nya; INSERT di transaksi yang sama berhasil.
        $this->assertSame('off', DB::selectOne('show transaction_read_only')->transaction_read_only);
        $this->assertNotSame('3s', DB::selectOne('show statement_timeout')->statement_timeout);
        $this->organization((string) $this->membership->tenant_id, 'operating_unit', 'Unit sesudah analitik');
    }

    public function test_switch_off_answers_not_found_for_page_and_api(): void
    {
        $this->actingAs($this->owner)->get('/analytics/explore')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/analytics/explore')
                ->where('preview.dataset.code', self::DATASET)
                ->where('preview.tile.query', ['dataset' => self::DATASET, 'measures' => ['count']])
                ->where('preview.tile.caption', 'Jumlah aset')
                ->where('preview.chart.query', ['dataset' => self::DATASET, 'dimensions' => ['lifecycle_state'], 'measures' => ['acquisition_value']])
                ->where('preview.chart.caption', 'Nilai perolehan per status aset')
                ->where('analyticsEnabled', true));

        config(['analytics.enabled' => false]);

        $this->actingAs($this->owner)->get('/analytics/explore')->assertNotFound();
        $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', ['dataset' => self::DATASET, 'measures' => ['count']])->assertNotFound();
    }

    public function test_page_without_readable_dataset_offers_nothing(): void
    {
        $this->actingAs($this->member(['management-aset.pemeliharaan-aset.manage']))->get('/analytics/explore')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('platform/analytics/explore')->where('preview', null));
    }

    private function business(string $name, string $email): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => $name, 'app_ids' => ['management-aset'], 'email' => $email, 'password' => 'password',
        ]);
    }

    /**
     * Anggota tenant A dengan duty ini saja, dan hibah kebijakan aset per pasangan legal entity dan unit.
     *
     * @param  list<string>  $duties
     * @param  list<array{0: string, 1: string}>  $grants
     */
    private function member(array $duties, array $grants = []): User
    {
        $user = User::factory()->create();
        $membership = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $this->grantDuties($membership, $duties);

        $assignment = RoleAssignment::query()->where('membership_id', $membership->id)->firstOrFail();
        foreach ($grants as [$legalEntity, $unit]) {
            $assignment->dataPolicyScopes()->create([
                'tenant_id' => $membership->tenant_id, 'policy_code' => self::POLICY,
                'legal_entity_id' => $legalEntity, 'organization_id' => $unit,
                'hierarchy_id' => null, 'hierarchy_version_id' => null, 'include_descendants' => false,
                'valid_from' => now()->subMinute(),
            ]);
        }

        return $user;
    }

    private function organization(string $tenantId, string $classification, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'classification' => $classification,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($classification === 'legal_entity') {
            DB::table('legal_entities')->insert([
                'organization_id' => $id, 'company_code' => Str::upper(Str::random(4)), 'country_code' => 'ID',
                'timezone' => 'Asia/Makassar', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** @return array{group: string, jenis: string} */
    private function masters(string $tenantId): array
    {
        $master = static function (string $table, string $code) use ($tenantId): string {
            $id = (string) Str::ulid();
            DB::table($table)->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'creation_key' => 'seed-'.Str::ulid(),
                'kode' => $code, 'nama' => $code, 'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        };

        return ['group' => $master('aset_m_group_aset', 'GRP-ANL'), 'jenis' => $master('aset_m_jenis_aset', 'JNS-ANL')];
    }

    /** @param array{group: string, jenis: string} $masters */
    private function asset(string $tenantId, array $masters, string $code, string $unit, ?string $financialUnit, string $value, string $currency, string $status = 'received', ?string $legalEntity = null): void
    {
        DB::table('aset_tr_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $code, 'nama' => 'Aset '.$code, 'legal_entity_id' => $legalEntity ?? $this->legalEntity,
            'responsible_org_unit_id' => $unit, 'financial_dimension_org_unit_id' => $financialUnit,
            'group_aset_id' => $masters['group'], 'jenis_aset_id' => $masters['jenis'],
            'acquired_on' => '2026-09-01', 'acquisition_value' => $value, 'currency_code' => $currency,
            'lifecycle_state' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
