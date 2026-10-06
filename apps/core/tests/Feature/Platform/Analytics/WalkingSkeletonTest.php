<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\DataPolicyScope;
use App\Platform\Analytics\Security\ScopeFingerprint;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\TenantProvisioned;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use LogicException;
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
 * jejak di transaksi pemanggilnya. Area 4 menambah jangkauan principal: hibah seluruh organisasi, saringan
 * terkunci yang hanya menyempitkan, dan sidik jari scope. Setiap test pernah dilihat merah dengan merusak
 * penangkalnya; caranya ditulis di pull request area 0 dan area 4.
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
            [['filters' => ['serial_number' => '*laptop*']], 'analytics.field_unknown', 'filters.serial_number'],
            [['dimensions' => ['lifecycle_state', 'keterangan']], 'analytics.field_unknown', 'dimensions.1'],
            [['measures' => ['count', 'nilai_buku']], 'analytics.field_unknown', 'measures.1'],
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

    /**
     * Penjelajah (area 8) hanya menerima daftar data yang boleh dibaca dan hak menyimpan; query-nya tinggal di
     * query string dan dibaca layar, jadi halaman yang dibuka dengan query tetap memulangkan prop yang sama.
     */
    public function test_page_offers_the_readable_datasets_and_ignores_the_query_string(): void
    {
        $catalog = ['management-aset.asset-register', 'management-aset.book-values'];
        $query = urlencode((string) json_encode(['dataset' => 'management-aset.asset-register', 'measures' => ['count']]));

        foreach (['/analytics/explore', "/analytics/explore?q={$query}&view=column"] as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('platform/analytics/explore')
                    ->where('datasets', fn ($datasets): bool => array_diff($catalog, collect($datasets)->pluck('code')->all()) === [])
                    ->where('datasets.0', fn ($dataset): bool => collect($dataset)->keys()->all() === ['code', 'caption', 'description', 'module_id', 'version', 'shared_dimensions'])
                    ->where('datasets.0.shared_dimensions', fn ($dimensions): bool => in_array('core.legal-entity', $dimensions, true))
                    ->where('abilities', ['create' => true, 'share' => true])
                    ->missing('preview'));
        }
    }

    public function test_page_without_readable_dataset_offers_nothing(): void
    {
        $this->actingAs($this->member(['management-aset.group-aset.manage']))->get('/analytics/explore')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/analytics/explore')
                ->where('datasets', [])
                ->where('abilities', ['create' => true, 'share' => false]));
    }

    /**
     * Rentang waktu relatif (area 2): token diterjemahkan menurut zona pengguna, dan tanggal di kolom
     * `date` dibandingkan apa adanya. Saat dibekukan, 31 Oktober 16.30 UTC adalah 1 November 00.30 WITA
     * tetapi masih 31 Oktober 23.30 WIB: pembacaan zona yang salah (UTC) menjawab Oktober untuk keduanya.
     */
    public function test_time_range_follows_the_users_zone_and_narrows_rows(): void
    {
        $this->setAcquiredOn(['AST-A1' => '2026-09-01', 'AST-A2' => '2026-10-15', 'AST-A3' => '2026-12-31', 'AST-B1' => '2027-01-01', 'AST-B2' => '2026-10-01']);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 16:30:00', 'UTC'));

        $count = fn (array $query): mixed => $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [
            'dataset' => self::DATASET, 'measures' => ['count'], ...$query,
        ])->assertOk()->json('rows.0.count');

        // WITA: sudah November.
        $this->assertSame(0, $count(['time_range' => ['range' => '@this_month']]));
        $this->assertSame(2, $count(['time_range' => ['range' => '@last_month']]));
        $this->assertSame(4, $count(['time_range' => ['range' => '@this_year']]));
        $this->assertSame(0, $count(['time_range' => ['range' => '@last_year']]));
        $this->assertSame(0, $count(['time_range' => ['range' => '@yesterday']]));
        $this->assertSame(3, $count(['time_range' => ['field' => 'acquired_on', 'range' => '@year_to_date']]));

        // Ekspresi tanggal biasa memakai sintaks yang sama dengan saringan, dan bertemu saringan lain dengan DAN.
        $this->assertSame(2, $count(['time_range' => ['range' => '01/10/2026..31/10/2026']]));
        $this->assertSame(1, $count(['time_range' => ['range' => '>=01/01/2027']]));
        $this->assertSame(1, $count(['time_range' => ['range' => '@this_year'], 'filters' => ['lifecycle_state' => ['disposed']]]));

        // Zona pengguna diganti ke WIB: pada saat yang sama masih Oktober.
        DB::table('legal_entities')->where('organization_id', $this->legalEntity)->update(['timezone' => 'Asia/Jakarta']);
        $this->assertSame(2, $count(['time_range' => ['range' => '@this_month']]));
        $this->assertSame(1, $count(['time_range' => ['range' => '@last_month']]));
    }

    public function test_sort_orders_the_rows_and_a_top_n_cut_follows_that_order(): void
    {
        $status = fn (array $query): array => $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [
            'dataset' => self::DATASET, 'dimensions' => ['lifecycle_state'], 'measures' => ['count', 'acquisition_value'], ...$query,
        ])->assertOk()->json('rows.*.lifecycle_state');

        // Tanpa urutan: jumlah terbanyak lebih dulu (lihat test pertama). Urutan pengguna menggantinya.
        $this->assertSame(['received', 'decommissioned', 'disposed'], $status([]));
        $this->assertSame(['received', 'disposed', 'decommissioned'], $status(['sort' => [['key' => 'acquisition_value', 'direction' => 'desc']]]));
        $this->assertSame(['decommissioned', 'disposed', 'received'], $status(['sort' => [['key' => 'acquisition_value', 'direction' => 'asc']]]));
        $this->assertSame(['decommissioned', 'disposed', 'received'], $status(['sort' => [['key' => 'lifecycle_state', 'direction' => 'asc']]]));
        $this->assertSame(['received', 'disposed', 'decommissioned'], $status(['sort' => [['key' => 'lifecycle_state', 'direction' => 'desc']]]));
        // Urutan kedua memutus seri urutan pertama: dua status sama-sama satu aset.
        $this->assertSame(['disposed', 'decommissioned', 'received'], $status(['sort' => [['key' => 'count', 'direction' => 'asc'], ['key' => 'lifecycle_state', 'direction' => 'desc']]]));

        // Potongan top-N diambil sesudah diurutkan, bukan sebelum.
        $top = $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [
            'dataset' => self::DATASET, 'dimensions' => ['lifecycle_state'], 'measures' => ['acquisition_value'], 'limit' => 1,
            'sort' => [['key' => 'acquisition_value', 'direction' => 'asc']],
        ])->assertOk();
        $top->assertJsonPath('rows.0.lifecycle_state', 'decommissioned')->assertJsonPath('meta.truncated', true)->assertJsonPath('meta.row_limit', 1);
        $this->assertCount(1, $top->json('rows'));
    }

    public function test_equivalent_queries_share_one_hash_and_different_ones_do_not(): void
    {
        $hash = fn (array $query): string => (string) $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', $query)->assertOk()->json('meta.query_hash');

        $one = $hash([
            'dataset' => self::DATASET, 'measures' => ['count'],
            'filters' => ['lifecycle_state' => ['received', 'disposed'], 'currency_code' => ' IDR '],
        ]);
        // Urutan kunci dan pilihan berbeda, pilihan berulang, spasi di ujung isian, dan isian kosong pada
        // kolom yang tidak ada (tidak menyaring apa pun, jadi dibuang sebelum diperiksa).
        $same = $hash([
            'filters' => ['currency_code' => 'IDR', 'lifecycle_state' => ['disposed', 'received', 'received'], 'kosong' => ''],
            'measures' => ['count'], 'dataset' => self::DATASET, 'totals' => false,
        ]);
        $other = $hash(['dataset' => self::DATASET, 'measures' => ['count'], 'filters' => ['lifecycle_state' => ['received'], 'currency_code' => 'IDR']]);

        $this->assertSame($one, $same);
        $this->assertNotSame($one, $other);
    }

    public function test_query_shape_errors_name_the_part_that_is_wrong(): void
    {
        $cases = [
            [['dimensions' => [['field' => 'lifecycle_state', 'granularity' => 'month']]], 'analytics.invalid_query', 'dimensions.0.granularity', 'bukan kolom tanggal'],
            [['dimensions' => [['field' => 'acquired_on', 'granularity' => 'hour']]], 'analytics.invalid_query', 'dimensions.0.granularity', 'day, week, month, quarter, year'],
            [['dimensions' => ['lifecycle_state', 'group_aset_id', 'currency_code', 'acquired_on', 'responsible_org_unit_id']], 'analytics.limit_exceeded', 'dimensions', 'Maksimal 4'],
            [['time_range' => ['range' => '@next_month']], 'analytics.invalid_query', 'time_range.range', '@this_month'],
            [['time_range' => ['field' => 'lifecycle_state', 'range' => '@today']], 'analytics.invalid_query', 'time_range.field', 'bukan kolom tanggal'],
            [['time_range' => ['field' => 'serial_number', 'range' => '@today']], 'analytics.field_unknown', 'time_range.field', 'tidak dikenal'],
            [['time_range' => ['range' => 'bukan tanggal']], 'analytics.invalid_filter', 'time_range.range', 'bukan tanggal'],
            [['time_range' => ['range' => '']], 'analytics.invalid_query', 'time_range.range', 'Isi rentang waktu'],
            [['filters' => ['acquired_on' => 'bukan tanggal']], 'analytics.invalid_filter', 'filters.acquired_on', 'bukan tanggal'],
            [['sort' => [['key' => 'lifecycle_state', 'direction' => 'asc']]], 'analytics.invalid_query', 'sort.0.key', 'tidak ada di pilihan'],
            [['sort' => [['key' => 'count', 'direction' => 'naik']]], 'analytics.invalid_query', 'sort.0.direction', 'asc atau desc'],
            [['compare' => 'previous_period'], 'analytics.invalid_query', 'compare', 'butuh rentang waktu'],
            [['formulas' => [['key' => 'rasio', 'expression' => 'BAGI([count]; 0']], 'measures' => ['rasio']], 'analytics.invalid_formula', 'formulas.0.expression', 'karakter 5'],
        ];

        foreach ($cases as [$part, $code, $field, $message]) {
            $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [...['dataset' => self::DATASET, 'measures' => ['count']], ...$part])
                ->assertStatus(422)->assertJsonPath('error.code', $code)->assertJsonPath('error.field', $field)
                ->assertJsonPath('error.message', fn (string $text): bool => str_contains($text, $message));
        }
    }

    /*
     * Area 4: jangkauan principal ({@see DataPolicyScope}) dan sidik jarinya ({@see ScopeFingerprint}).
     */

    public function test_full_grant_reads_every_row_and_the_scope_fingerprint_follows_the_grant(): void
    {
        $everything = $this->member(['management-aset.aset.manage'], [[null, null]]);
        $this->actingAs($everything)->postJson('/api/v1/analytics/query', ['dataset' => self::DATASET, 'measures' => ['count']])->assertOk()
            ->assertJsonPath('rows', [['count' => 5]]);

        $dataset = app(DatasetRegistry::class)->find(self::DATASET);
        $this->assertNotNull($dataset);
        $fingerprint = fn (User $user): string => UserPrincipal::fromMembership($user->activeMembership() ?? throw new LogicException('Tanpa keanggotaan.'), 'UTC')->fingerprint($dataset);

        // Dua pengguna dengan hibah sama berbagi sidik jari (dan kelak cache); hibah berbeda tidak pernah.
        $unitA = $fingerprint($this->member(['management-aset.aset.manage'], [[$this->legalEntity, $this->unitA]]));
        $this->assertSame($unitA, $fingerprint($this->member(['management-aset.aset.manage'], [[$this->legalEntity, $this->unitA]])));
        $this->assertNotSame($unitA, $fingerprint($this->member(['management-aset.aset.manage'], [[$this->legalEntity, $this->unitB]])));
        $this->assertNotSame($unitA, $fingerprint($everything));
        // Hak data pribadi mengubah label yang boleh tampil, jadi ikut membedakan.
        $this->assertNotSame($fingerprint($everything), $fingerprint($this->member(['management-aset.aset.manage', 'core.analytics.personal-data'], [[null, null]])));
    }

    public function test_locked_filters_only_narrow_and_an_empty_or_unknown_one_reads_nothing(): void
    {
        /**
         * @param  array<string, string|list<string>>  $locked
         * @param  array<string, string|list<string>>  $filters
         */
        $count = function (array $locked, array $filters = []): int {
            $result = app(RunQuery::class)->handle(
                $this->lockedTo($locked),
                new AnalyticsQuery(self::DATASET, [], ['count'], $filters, null, [], null, false, false),
            )->toArray();

            return (int) $result['rows'][0]['count'];
        };

        $this->assertSame(5, $count([]));
        $this->assertSame(3, $count(['lifecycle_state' => ['received']]));
        // Saringan pengguna menyempitkan di atas saringan terkunci, tidak pernah menggantikannya.
        $this->assertSame(0, $count(['lifecycle_state' => ['received']], ['lifecycle_state' => ['disposed', 'decommissioned']]));
        $this->assertSame(1, $count(['lifecycle_state' => ['received', 'disposed']], ['lifecycle_state' => ['disposed']]));
        // Gagal tertutup: daftar kosong, teks kosong, dan field yang tidak dikenal dataset berarti nol baris.
        $this->assertSame(0, $count(['lifecycle_state' => []]));
        $this->assertSame(0, $count(['currency_code' => '  ']));
        $this->assertSame(0, $count(['kolom_yang_sudah_tidak_ada' => 'x']));
    }

    /**
     * Area 3 lewat endpoint yang sama dengan layar: ember bulan beserta celahnya dan total per mata uang,
     * dalam bentuk JSON yang dibaca layar. Label rujukan dan dimensi bersama diuji `QueryCompilerTest`,
     * karena dataset aset baru menyatakannya di area 5.
     */
    public function test_time_buckets_gaps_and_totals_reach_the_screen(): void
    {
        $this->setAcquiredOn(['AST-A1' => '2026-07-10', 'AST-A2' => '2026-09-15', 'AST-A3' => '2026-09-30', 'AST-B1' => '2026-07-01', 'AST-B2' => '2026-09-02']);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/analytics/query', [
            'dataset' => self::DATASET,
            'dimensions' => [['field' => 'acquired_on', 'granularity' => 'month']],
            'measures' => ['count', 'acquisition_value'],
            'filters' => ['currency_code' => 'IDR'],
            'totals' => true,
        ])->assertOk();

        $response->assertJsonPath('columns.0', ['key' => 'acquired_on', 'kind' => 'dimension', 'caption' => 'Tanggal perolehan', 'type' => 'period', 'granularity' => 'month']);
        // Agustus tanpa aset tetap muncul dengan nol.
        $response->assertJsonPath('rows', [
            ['acquired_on' => '2026-07-01', 'currency_code' => 'IDR', 'count' => 2, 'acquisition_value' => '300000000.00'],
            ['acquired_on' => '2026-08-01', 'currency_code' => 'IDR', 'count' => 0, 'acquisition_value' => '0'],
            ['acquired_on' => '2026-09-01', 'currency_code' => 'IDR', 'count' => 2, 'acquisition_value' => '60000000.50'],
        ]);
        $response->assertJsonPath('totals', [['currency_code' => 'IDR', 'count' => 4, 'acquisition_value' => '360000000.50']]);
    }

    public function test_explain_prints_the_compiled_sql_and_its_plan_for_one_member(): void
    {
        $options = [
            '--query' => (string) json_encode(['dataset' => self::DATASET, 'dimensions' => ['group_aset_id'], 'measures' => ['acquisition_value'], 'totals' => true]),
            '--tenant' => (string) $this->membership->tenant_id,
            '--user' => 'owner-a@analitik.test',
        ];

        $this->artisan('analytics:explain', $options)
            ->expectsOutputToContain('Dataset '.self::DATASET.' versi 1')
            ->expectsOutputToContain('from "aset_tr_aset" where "aset_tr_aset"."tenant_id" = ')
            ->expectsOutputToContain('Rencana (EXPLAIN, tanpa ANALYZE)')
            ->expectsOutputToContain('Rencana total')
            ->assertSuccessful();

        $this->artisan('analytics:explain', [...$options, '--user' => 'bukan-anggota@analitik.test'])
            ->expectsOutputToContain('bukan anggota aktif')->assertFailed();
        $this->artisan('analytics:explain', [...$options, '--query' => '{"dataset": "'.self::DATASET.'", "measures": ["nilai_buku"]}'])
            ->expectsOutputToContain('tidak dikenal')->assertFailed();
    }

    private function business(string $name, string $email): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => $name, 'app_ids' => ['management-aset'], 'email' => $email, 'password' => 'password',
        ]);
    }

    /**
     * Anggota tenant A dengan duty ini saja, dan hibah kebijakan aset per pasangan legal entity dan unit.
     * Duty *Susun dasbor dan analisis data* selalu ikut, supaya gate rute analitik (area 4) terlewati dan
     * yang diuji adalah langkah sesudahnya. Pasangan `[null, null]` adalah hibah seluruh organisasi.
     *
     * @param  list<string>  $duties
     * @param  list<array{0: ?string, 1: ?string}>  $grants
     */
    private function member(array $duties, array $grants = []): User
    {
        $user = User::factory()->create();
        $membership = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $this->grantDuties($membership, ['core.analytics.analyze', ...$duties]);

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

    /** @param array<string, string> $dates kode aset => tanggal perolehan */
    private function setAcquiredOn(array $dates): void
    {
        foreach ($dates as $code => $date) {
            DB::table('aset_tr_aset')->where('kode', $code)->update(['acquired_on' => $date]);
        }
    }

    /**
     * Owner tenant A dengan saringan terkunci, seperti principal publikasi kelak (area 15): method lain
     * diteruskan ke principal pengguna sungguhan.
     *
     * @param  array<string, string|list<string>>  $locked
     */
    private function lockedTo(array $locked): AnalyticsPrincipal
    {
        return new readonly class(UserPrincipal::fromMembership($this->membership, 'UTC'), $locked) implements AnalyticsPrincipal
        {
            /** @param array<string, string|list<string>> $locked */
            public function __construct(private UserPrincipal $user, private array $locked) {}

            public function tenantId(): string
            {
                return $this->user->tenantId();
            }

            public function holdsPermission(string $moduleId, string $permission): bool
            {
                return $this->user->holdsPermission($moduleId, $permission);
            }

            public function policyScope(string $policyCode): array
            {
                return $this->user->policyScope($policyCode);
            }

            public function mayUsePersonalData(): bool
            {
                return false;
            }

            public function lockedFilters(string $dataset): array
            {
                return $this->locked;
            }

            public function timezone(): string
            {
                return $this->user->timezone();
            }

            public function now(): CarbonImmutable
            {
                return $this->user->now();
            }

            public function rowLimit(): int
            {
                return $this->user->rowLimit();
            }

            public function timeoutMs(): int
            {
                return $this->user->timeoutMs();
            }

            public function fingerprint(CompiledDataset $dataset): string
            {
                return ScopeFingerprint::of($this, $dataset->code, $dataset->policy['code'] ?? null);
            }

            public function describe(): string
            {
                return 'locked:'.$this->user->describe();
            }
        };
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
