<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Datasets\DatasetValidator;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\CompiledQuery;
use App\Platform\Analytics\Query\LabelResolver;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Barang;
use Modules\Apperp\ContohA\Models\Penjualan;
use Tests\TestCase;

/**
 * Compiler, eksekusi, dan hasil (area 3, `docs/todo/analitik/mesin-query.md`) di atas dataset bahan uji
 * `contoh-a.penjualan`: join hanya yang disebut dan selalu bertenant sama, label rujukan termasuk master
 * terarsip tetapi tidak pernah dari tenant lain, ember waktu menurut zona pengguna untuk tiga jenis kolom
 * waktu, measure bersaringan, total per mata uang, urutan bawaan dan kosong di akhir, celah deret waktu,
 * batas waktu yang benar-benar menghentikan query, dan baca-saja yang ditegakkan database.
 *
 * Query dijalankan lewat langkah yang sama dengan `RunQuery` tanpa pemeriksaan pemasangan module, karena
 * module contoh tidak pernah dipasang untuk tenant; jalur HTTP lengkap diuji `WalkingSkeletonTest`.
 * Setiap penjaga pernah dilihat merah dengan merusak penangkalnya; caranya dicatat di pull request area 3.
 */
class QueryCompilerTest extends TestCase
{
    use RefreshDatabase;

    private const SALES = 'contoh-a.penjualan';

    private string $tenantA;

    private string $tenantB;

    private string $legalEntity;

    private string $unitA;

    private string $unitB;

    /** @var array<string, string> kode barang => id */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Tabel module contoh dibuat test-nya sendiri, seperti penjaga batas lain.
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->tenantA = $this->tenant('Tenant penjualan A');
        $this->tenantB = $this->tenant('Tenant penjualan B');
        $this->legalEntity = $this->organization($this->tenantA, 'legal_entity', 'CV Contoh');
        $this->unitA = $this->organization($this->tenantA, 'operating_unit', 'Unit Utara');
        $this->unitB = $this->organization($this->tenantA, 'operating_unit', 'Unit Selatan');

        $this->item($this->tenantA, 'B1', 'Barang Satu', true);
        $this->item($this->tenantA, 'B2', 'Barang Dua', false, archived: true);
        // Barang tenant lain. Penjualan tenant A yang menunjuknya adalah data rusak, bukan keadaan sah.
        $this->item($this->tenantB, 'X1', 'Barang Tenant B', true);

        $this->sale($this->tenantA, 'B1', $this->unitA, 'terbit', '100.00', 'IDR', '2026-01-10');
        $this->sale($this->tenantA, 'B1', $this->unitB, 'draf', '50.50', 'IDR', '2026-03-05');
        $this->sale($this->tenantA, 'B2', $this->unitA, 'terbit', '20.00', 'USD', '2026-03-20');
        $this->sale($this->tenantA, 'X1', $this->unitA, 'batal', '10.00', 'IDR', '2026-01-25');
        $this->sale($this->tenantB, 'X1', (string) Str::ulid(), 'terbit', '999.00', 'IDR', '2026-01-15');
    }

    public function test_compiled_sql_never_aliases_the_base_table(): void
    {
        $sql = $this->sql([
            'dataset' => self::SALES,
            'dimensions' => ['barang_id', 'barang_bawaan', ['field' => 'dicatat_pada', 'granularity' => 'month']],
            'measures' => ['nilai', 'terbit', 'rata_rata_nilai'],
            'filters' => ['status' => ['terbit', 'draf'], 'barang_bawaan' => ['1']],
            'time_range' => ['range' => '@this_year'],
            'sort' => [['key' => 'rata_rata_nilai', 'direction' => 'desc']],
            'totals' => true,
        ]);

        $this->assertNotNull($sql['totals']);
        foreach ($sql as $part => $statement) {
            $this->assertStringContainsString('from "contoh_a_tr_penjualan"', (string) $statement, "SQL {$part} tidak membaca tabel dasar dengan namanya.");
            $this->assertDoesNotMatchRegularExpression('/"contoh_a_tr_penjualan"\s+as\s/i', (string) $statement, "SQL {$part} memberi alias tabel dasar; scope tenant menunjuk nama tabel sebenarnya.");
            $this->assertStringContainsString('"contoh_a_tr_penjualan"."tenant_id" = ?', (string) $statement, "SQL {$part} kehilangan saringan tenant model.");
        }
    }

    public function test_only_the_joins_a_query_mentions_are_attached_and_each_carries_the_tenant(): void
    {
        $plain = $this->sql(['dataset' => self::SALES, 'dimensions' => ['status'], 'measures' => ['count']])['rows'];
        $this->assertStringNotContainsString(' join ', $plain);

        $data = 'left join "contoh_a_m_barang" as "barang" on "barang"."id" = "contoh_a_tr_penjualan"."barang_id" and "barang"."tenant_id" = "contoh_a_tr_penjualan"."tenant_id" and "barang"."deleted_at" is null';
        $label = 'left join "contoh_a_m_barang" as "r0" on "r0"."id" = "contoh_a_tr_penjualan"."barang_id" and "r0"."tenant_id" = "contoh_a_tr_penjualan"."tenant_id"';

        // Kolom join sebagai pengelompok, atau hanya sebagai saringan: join data saja, tanpa join label.
        foreach ([['dimensions' => ['barang_bawaan']], ['filters' => ['barang_bawaan' => ['1']]]] as $part) {
            $sql = $this->sql(['dataset' => self::SALES, 'measures' => ['count'], ...$part])['rows'];
            $this->assertStringContainsString($data, $sql);
            $this->assertStringNotContainsString('"r0"', $sql);
        }

        // Rujukan sebagai pengelompok: join label saja, yang menyertakan master terarsip.
        $sql = $this->sql(['dataset' => self::SALES, 'dimensions' => ['barang_id'], 'measures' => ['count']])['rows'];
        $this->assertStringContainsString($label, $sql);
        $this->assertStringNotContainsString('"r0"."deleted_at"', $sql);
        $this->assertStringNotContainsString('as "barang"', $sql);
    }

    public function test_a_child_pointing_at_another_tenants_master_carries_neither_its_label_nor_its_values(): void
    {
        $result = $this->analyse(['dataset' => self::SALES, 'dimensions' => ['barang_id'], 'measures' => ['count']]);
        $byItem = array_column($result->rows, null, 'barang_id');

        $this->assertSame('Barang Satu', $byItem[$this->items['B1']]['barang_id__label']);
        // Label join tanpa syarat tenant akan memulangkan "Barang Tenant B" di sini.
        $this->assertNull($byItem[$this->items['X1']]['barang_id__label']);
        $this->assertStringNotContainsString('Barang Tenant B', (string) json_encode($result->toArray()));

        // Join data juga: baris yang menunjuk tenant lain tetap dihitung, dengan kolom join kosong.
        $bawaan = $this->analyse(['dataset' => self::SALES, 'dimensions' => ['barang_bawaan'], 'measures' => ['count'], 'sort' => [['key' => 'barang_bawaan', 'direction' => 'asc']]]);
        $this->assertSame([
            ['barang_bawaan' => true, 'barang_bawaan__label' => 'Ya', 'count' => 2],
            ['barang_bawaan' => null, 'barang_bawaan__label' => null, 'count' => 2],
        ], $bawaan->rows);
    }

    public function test_archived_masters_keep_their_label_but_archived_rows_never_match_a_data_join(): void
    {
        $result = $this->analyse(['dataset' => self::SALES, 'dimensions' => ['barang_id'], 'measures' => ['count']]);
        $byItem = array_column($result->rows, null, 'barang_id');
        $this->assertSame('Barang Dua', $byItem[$this->items['B2']]['barang_id__label']);

        // Barang Dua — satu-satunya barang bukan bawaan — sudah diarsipkan: kolom join-nya tidak pernah cocok.
        $this->assertSame([['count' => 0]], $this->analyse(['dataset' => self::SALES, 'measures' => ['count'], 'filters' => ['barang_bawaan' => ['0']]])->rows);
    }

    /**
     * 30 September 2026 pukul 16.30 UTC adalah 1 Oktober 00.30 WITA, tetapi masih 30 September 23.30 WIB.
     * Kolom `date` tidak dikonversi: tanggal penjualan adalah tanggal kalender, bukan saat.
     */
    public function test_month_buckets_follow_the_users_zone_for_each_kind_of_time_column(): void
    {
        $this->sale($this->tenantA, 'B1', $this->unitA, 'terbit', '5.00', 'IDR', '2026-09-30', '2026-09-30 16:30:00', '2026-09-30 16:30:00+00');
        $bucket = fn (string $field, string $granularity, string $zone): mixed => $this->analyse([
            'dataset' => self::SALES, 'dimensions' => [['field' => $field, 'granularity' => $granularity]], 'measures' => ['count'],
            'time_range' => ['field' => 'tanggal', 'range' => '30/09/2026'],
        ], $zone)->rows[0][$field] ?? null;

        $this->assertSame('2026-10-01', $bucket('dicatat_pada', 'month', 'Asia/Makassar'));
        $this->assertSame('2026-09-01', $bucket('dicatat_pada', 'month', 'UTC'));
        $this->assertSame('2026-09-01', $bucket('dicatat_pada', 'month', 'Asia/Jakarta'));
        $this->assertSame('2026-10-01', $bucket('dibayar_pada', 'month', 'Asia/Makassar'));
        $this->assertSame('2026-09-01', $bucket('dibayar_pada', 'month', 'UTC'));
        $this->assertSame('2026-09-01', $bucket('tanggal', 'month', 'Asia/Makassar'));
        $this->assertSame('2026-10-01', $bucket('dicatat_pada', 'day', 'Asia/Makassar'));
        $this->assertSame('2026-10-01', $bucket('dicatat_pada', 'quarter', 'Asia/Makassar'));
        $this->assertSame('2026-07-01', $bucket('dicatat_pada', 'quarter', 'UTC'));
        $this->assertSame('2026-01-01', $bucket('dibayar_pada', 'year', 'Asia/Jayapura'));
        // Minggu mulai Senin: Rabu 30 September dan Kamis 1 Oktober sama-sama minggu 28 September.
        $this->assertSame('2026-09-28', $bucket('tanggal', 'week', 'Asia/Makassar'));
        $this->assertSame('2026-09-28', $bucket('dicatat_pada', 'week', 'Asia/Makassar'));
    }

    public function test_measures_count_sum_and_respect_their_fixed_filters(): void
    {
        $this->assertSame([
            ['currency_code' => 'IDR', 'currency_code__label' => 'IDR', 'count' => 3, 'terbit' => 1, 'nilai' => '160.50', 'barang_terjual' => 2],
            ['currency_code' => 'USD', 'currency_code__label' => 'USD', 'count' => 1, 'terbit' => 1, 'nilai' => '20.00', 'barang_terjual' => 1],
        ], $this->analyse(['dataset' => self::SALES, 'dimensions' => ['currency_code'], 'measures' => ['count', 'terbit', 'nilai', 'barang_terjual']])->rows);

        // Saringan tetap pada kolom ya/tidak, dan baris terarsip tabel dasar tidak ikut.
        $this->assertSame([['count' => 1, 'bawaan' => 1]], $this->analyse(['dataset' => 'contoh-a.barang', 'measures' => ['count', 'bawaan']])->rows);
    }

    public function test_totals_give_one_row_per_currency_over_every_group_with_the_same_filters(): void
    {
        $totals = fn (array $query, ?array $scope = null): array => $this->analyse(['dataset' => self::SALES, 'totals' => true, ...$query], scope: $scope)->totals;

        $both = [
            ['currency_code' => 'IDR', 'count' => 3, 'nilai' => '160.50'],
            ['currency_code' => 'USD', 'count' => 1, 'nilai' => '20.00'],
        ];
        $this->assertSame($both, $totals(['dimensions' => ['status'], 'measures' => ['count', 'nilai']]));
        // Total menghitung seluruh kelompok, bukan hanya yang lolos top-N.
        $this->assertSame($both, $totals(['dimensions' => ['status'], 'measures' => ['count', 'nilai'], 'limit' => 1]));
        // Saringan pengguna dan kebijakan data menyaring total persis seperti hasilnya.
        $this->assertSame([
            ['currency_code' => 'IDR', 'count' => 1, 'nilai' => '100.00'],
            ['currency_code' => 'USD', 'count' => 1, 'nilai' => '20.00'],
        ], $totals(['dimensions' => ['status'], 'measures' => ['count', 'nilai'], 'filters' => ['status' => ['terbit']]]));
        $this->assertSame([
            ['currency_code' => 'IDR', 'count' => 2, 'nilai' => '110.00'],
            ['currency_code' => 'USD', 'count' => 1, 'nilai' => '20.00'],
        ], $totals(['dimensions' => ['status'], 'measures' => ['count', 'nilai']], ['all' => false, 'scope_grants' => [['legal_entity_id' => $this->legalEntity, 'operating_unit_ids' => [$this->unitA]]]]));

        // Mata uang dipilih sebagai dimensi: total tetap per mata uang, dengan kunci dan label dimensi itu.
        $this->assertSame([
            ['currency_code' => 'IDR', 'currency_code__label' => 'IDR', 'nilai' => '160.50'],
            ['currency_code' => 'USD', 'currency_code__label' => 'USD', 'nilai' => '20.00'],
        ], $totals(['dimensions' => ['status', 'currency_code'], 'measures' => ['nilai']]));
        // Tanpa measure uang: satu baris.
        $this->assertSame([['count' => 4]], $totals(['dimensions' => ['status'], 'measures' => ['count']]));
        $this->assertSame([], $this->analyse(['dataset' => self::SALES, 'measures' => ['count']])->totals);
    }

    public function test_default_order_and_empty_values_last_on_descending_order(): void
    {
        $statuses = fn (array $query): array => array_column($this->analyse(['dataset' => self::SALES, 'dimensions' => ['status'], ...$query])->rows, 'status');

        // Tanpa periode: measure pertama turun, seri diputus status naik.
        $this->assertSame(['terbit', 'batal', 'draf'], $statuses(['measures' => ['count']]));
        // Dengan periode: periode naik, walau measure-nya lebih besar di periode berikutnya (Januari 1, Maret 2).
        $this->assertSame([['2026-01-01', 1], ['2026-03-01', 2]], array_map(static fn (array $row): array => [$row['tanggal'], $row['count']], $this->analyse([
            'dataset' => self::SALES, 'dimensions' => [['field' => 'tanggal', 'granularity' => 'month']], 'measures' => ['count'],
            'filters' => ['status' => ['terbit', 'draf']], 'fill_gaps' => false,
        ])->rows));

        // Rata-rata yang kosong jatuh di akhir pada urutan turun, bukan di depan.
        DB::table('contoh_a_tr_penjualan')->where('status', 'terbit')->where('currency_code', 'IDR')->update(['dicatat_oleh_user_id' => 7]);
        DB::table('contoh_a_tr_penjualan')->where('status', 'draf')->update(['dicatat_oleh_user_id' => 9]);
        $dataset = $this->compileDataset(DatasetDefinition::make('contoh-a.penjualan-pencatat', 'Penjualan per pencatat')
            ->model(Penjualan::class)
            ->permission('contoh-a.penjualan.read')
            ->dataPolicy('contoh-a.penjualan-unit', legalEntity: 'legal_entity_id', operatingUnit: 'org_unit_id')
            ->fieldsFromModel(only: ['status'])
            ->measure('rata_rata_pencatat', 'Rata-rata id pencatat', Aggregate::Average, field: 'dicatat_oleh_user_id'));
        $this->assertSame(['draf', 'terbit', 'batal'], array_column($this->analyse([
            'dataset' => $dataset->code, 'dimensions' => ['status'], 'measures' => ['rata_rata_pencatat'],
        ], dataset: $dataset)->rows, 'status'));

        // Dimensi juga: kelompok tanpa barang (join kosong) di akhir pada urutan turun.
        $this->assertSame([true, null], array_column($this->analyse([
            'dataset' => self::SALES, 'dimensions' => ['barang_bawaan'], 'measures' => ['count'], 'sort' => [['key' => 'barang_bawaan', 'direction' => 'desc']],
        ])->rows, 'barang_bawaan'));
    }

    public function test_gaps_in_a_time_series_are_filled_with_zero_for_counts_and_empty_for_averages(): void
    {
        $query = ['dataset' => self::SALES, 'dimensions' => [['field' => 'tanggal', 'granularity' => 'month']], 'measures' => ['count', 'nilai', 'rata_rata_nilai']];
        $rows = $this->analyse($query)->rows;

        $this->assertSame(['2026-01-01', '2026-01-01', '2026-02-01', '2026-02-01', '2026-03-01', '2026-03-01'], array_column($rows, 'tanggal'));
        $this->assertSame(['IDR', 'USD', 'IDR', 'USD', 'IDR', 'USD'], array_column($rows, 'currency_code'));
        // Februari tanpa penjualan: hitungan dan jumlah nol, rata-rata kosong — bukan nol.
        $this->assertSame(['tanggal' => '2026-02-01', 'currency_code' => 'IDR', 'count' => 0, 'nilai' => '0', 'rata_rata_nilai' => null], $rows[2]);
        $this->assertSame(['tanggal' => '2026-01-01', 'currency_code' => 'USD', 'count' => 0, 'nilai' => '0', 'rata_rata_nilai' => null], $rows[1]);
        $this->assertSame(2, $rows[0]['count']);

        // Rentang token pada field yang sama: seluruh rentangnya, termasuk bulan kosong di ujung.
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00', 'Asia/Makassar'));
        $year = $this->analyse([...$query, 'measures' => ['count'], 'time_range' => ['range' => '@this_year']])->rows;
        $this->assertCount(12, $year);
        $this->assertSame(['2026-01-01', 2], [$year[0]['tanggal'], $year[0]['count']]);
        $this->assertSame(['2026-12-01', 0], [$year[11]['tanggal'], $year[11]['count']]);

        // Tidak diisi bila diurutkan menurut nilai, bila dimatikan, atau bila isiannya melebihi batas baris.
        $this->assertCount(3, $this->analyse([...$query, 'sort' => [['key' => 'count', 'direction' => 'desc']]])->rows);
        $this->assertCount(3, $this->analyse([...$query, 'fill_gaps' => false])->rows);
        $this->assertCount(3, $this->analyse([...$query, 'limit' => 5])->rows);
    }

    public function test_labels_for_options_booleans_and_shared_dimensions(): void
    {
        $owner = User::factory()->create(['name' => 'Pencatat Penjualan']);
        TenantMembership::query()->create(['tenant_id' => $this->tenantA, 'user_id' => $owner->id, 'status' => 'active']);
        DB::table('contoh_a_tr_penjualan')->where('tenant_id', $this->tenantA)->update(['dicatat_oleh_user_id' => $owner->id]);

        $query = [
            'dataset' => self::SALES, 'dimensions' => ['status', 'org_unit_id', 'legal_entity_id', 'dicatat_oleh_user_id'], 'measures' => ['count'],
            'filters' => ['status' => ['draf']],
        ];

        $this->assertSame([[
            'status' => 'draf', 'status__label' => 'Draf',
            'org_unit_id' => $this->unitB, 'org_unit_id__label' => 'Unit Selatan',
            'legal_entity_id' => $this->legalEntity, 'legal_entity_id__label' => 'CV Contoh',
            'dicatat_oleh_user_id' => $owner->id, 'dicatat_oleh_user_id__label' => 'Pencatat Penjualan',
            'count' => 1,
        ]], $this->analyse($query, personalData: true)->rows);

        // Tanpa hak data pribadi: id pengguna tetap boleh sebagai pengelompok, tetapi namanya ditahan.
        $withheld = $this->analyse($query)->rows[0];
        $this->assertSame([$owner->id, null], [$withheld['dicatat_oleh_user_id'], $withheld['dicatat_oleh_user_id__label']]);
    }

    public function test_a_query_past_its_time_limit_is_stopped_and_answered_as_too_heavy(): void
    {
        $dataset = $this->compileDataset(DatasetDefinition::make('contoh-a.barang-lambat', 'Barang lambat')
            ->fromQuery(static fn () => Barang::query()->select('tenant_id')->selectRaw('pg_sleep(5)::text as tidur'))
            ->permission('contoh-a.barang.read')
            ->field('tidur', 'Tidur', FieldType::Text, classification: DataClass::SystemMetadata)
            ->measure('count', 'Jumlah', Aggregate::Count, field: 'tidur'));
        $started = hrtime(true);

        try {
            $this->analyse(['dataset' => $dataset->code, 'measures' => ['count']], dataset: $dataset, timeoutMs: 200);
            $this->fail('Query yang melewati batas waktu harus dihentikan database.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('analytics.query_timeout', $e->errorCode);
            $this->assertSame(422, $e->status);
        }

        // Benar-benar dihentikan, bukan ditunggu sampai selesai lalu ditolak.
        $this->assertLessThan(3000, intdiv(hrtime(true) - $started, 1_000_000));
        // Batal ke savepoint: batas waktu tidak tertinggal di transaksi pemanggil.
        $this->assertNotSame('200ms', DB::selectOne('show statement_timeout')->statement_timeout);
    }

    public function test_a_compiler_forced_to_write_fails_in_the_database(): void
    {
        DB::statement('create sequence analitik_uji_urut');
        $compiled = new CompiledQuery(Barang::query()->selectRaw("nextval('analitik_uji_urut') as m0"), null, [], 1);

        try {
            app(TenantRunner::class)->runFor($this->tenantA, static fn (): array => app(QueryExecutor::class)->run($compiled, 3000));
            $this->fail('Transaksi analitik harus baca-saja di database.');
        } catch (QueryException $e) {
            // Cacat compiler, bukan kesalahan pengguna: dilempar apa adanya, bukan dijadikan 422.
            $this->assertSame('25006', (string) $e->getCode());
        }

        // Transaksi pemanggil tetap dapat menulis sesudahnya.
        $this->assertSame(1, (int) DB::selectOne("select nextval('analitik_uji_urut') as n")->n);
    }

    /**
     * Saringan terkunci principal (publikasi, embed — area 4) menyaring query hasil **dan** query total, juga
     * bila ia memakai kolom join yang tidak disebut query mana pun: join-nya ikut terpasang.
     */
    public function test_locked_filters_narrow_both_the_rows_and_the_totals_even_through_a_join(): void
    {
        $query = ['dataset' => self::SALES, 'dimensions' => ['status'], 'measures' => ['count', 'nilai'], 'totals' => true];

        $open = $this->analyse($query);
        $this->assertSame([['currency_code' => 'IDR', 'count' => 3, 'nilai' => '160.50'], ['currency_code' => 'USD', 'count' => 1, 'nilai' => '20.00']], $open->totals);

        // Hanya penjualan barang bawaan aktif (Barang Satu): dua penjualan IDR.
        $locked = $this->analyse($query, locked: ['barang_bawaan' => ['1']]);
        $this->assertSame([['draf', 1], ['terbit', 1]], array_map(static fn (array $row): array => [$row['status'], $row['count']], $locked->rows));
        $this->assertSame([['currency_code' => 'IDR', 'count' => 2, 'nilai' => '150.50']], $locked->totals);

        // Kosong berarti nol baris, untuk hasil dan total.
        $empty = $this->analyse($query, locked: ['barang_bawaan' => []]);
        $this->assertSame([[], []], [$empty->rows, $empty->totals]);
    }

    public function test_explain_reads_the_plan_of_both_queries_without_running_them(): void
    {
        $query = app(QueryParser::class)->parse(['dataset' => self::SALES, 'dimensions' => ['barang_id'], 'measures' => ['nilai'], 'totals' => true]);
        $dataset = $this->dataset(self::SALES);
        $principal = $this->principal();

        $plan = app(TenantRunner::class)->runFor($this->tenantA, static fn (): array => app(QueryExecutor::class)
            ->explain(app(QueryCompiler::class)->compile($dataset, $query, $principal), 3000));

        $this->assertStringContainsString('contoh_a_tr_penjualan', implode("\n", $plan['rows']));
        $this->assertStringContainsString('contoh_a_m_barang r0', implode("\n", $plan['rows']));
        $this->assertNotSame([], $plan['totals']);
        $this->assertStringNotContainsString('actual time', implode("\n", [...$plan['rows'], ...$plan['totals']]));
    }

    /**
     * Langkah `RunQuery` tanpa pemeriksaan pemasangan module: bentuk normal, validasi, lalu compile,
     * eksekusi, dan penyusunan hasil di dalam tenant aktif.
     *
     * @param  array<string, mixed>  $input
     * @param  array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}|null  $scope
     * @param  array<string, string|list<string>>  $locked  saringan terkunci principal
     */
    private function analyse(array $input, string $timezone = 'Asia/Makassar', ?array $scope = null, ?CompiledDataset $dataset = null, int $timeoutMs = 3000, bool $personalData = false, array $locked = []): ResultSet
    {
        $query = app(QueryNormalizer::class)->normalize(app(QueryParser::class)->parse($input));
        $dataset ??= $this->dataset($query->dataset);
        $principal = $this->principal($timezone, $scope, $personalData, $locked);
        app(QueryValidator::class)->validate($dataset, $query, $principal);

        return app(TenantRunner::class)->runFor($this->tenantA, static function () use ($dataset, $query, $principal, $timeoutMs): ResultSet {
            $compiled = app(QueryCompiler::class)->compile($dataset, $query, $principal);

            return ResultSet::from($dataset, $query, $compiled, app(QueryExecutor::class)->run($compiled, $timeoutMs), $principal, 0, app(LabelResolver::class));
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{rows: string, totals: ?string}
     */
    private function sql(array $input): array
    {
        $query = app(QueryNormalizer::class)->normalize(app(QueryParser::class)->parse($input));
        $dataset = $this->dataset($query->dataset);
        $principal = $this->principal();

        return app(TenantRunner::class)->runFor($this->tenantA, static function () use ($dataset, $query, $principal): array {
            $compiled = app(QueryCompiler::class)->compile($dataset, $query, $principal);

            return ['rows' => $compiled->builder->toBase()->toSql(), 'totals' => $compiled->totals?->toBase()->toSql()];
        });
    }

    private function dataset(string $code): CompiledDataset
    {
        $dataset = app(DatasetRegistry::class)->find($code);
        $this->assertNotNull($dataset, "Dataset {$code} tidak tersedia.");

        return $dataset;
    }

    private function compileDataset(DatasetDefinition $definition): CompiledDataset
    {
        $validator = app(DatasetValidator::class);

        return $validator->compile($validator->declare(new readonly class($definition) implements Dataset
        {
            public function __construct(private DatasetDefinition $definition) {}

            public function moduleId(): string
            {
                return 'contoh-a';
            }

            public function definition(): DatasetDefinition
            {
                return $this->definition;
            }
        }));
    }

    /**
     * Principal tiruan tenant A. Tiruan, bukan kelas tulisan tangan, supaya method yang kelak ditambahkan ke
     * antarmukanya tidak mematahkan test ini.
     *
     * @param  array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}|null  $scope
     * @param  array<string, string|list<string>>  $locked
     */
    private function principal(string $timezone = 'Asia/Makassar', ?array $scope = null, bool $personalData = false, array $locked = []): AnalyticsPrincipal
    {
        $principal = $this->createStub(AnalyticsPrincipal::class);
        $principal->method('tenantId')->willReturn($this->tenantA);
        $principal->method('mayUsePersonalData')->willReturn($personalData);
        $principal->method('lockedFilters')->willReturn($locked);
        $principal->method('policyScope')->willReturn($scope ?? ['all' => true, 'scope_grants' => []]);
        $principal->method('timezone')->willReturn($timezone);
        $principal->method('now')->willReturnCallback(static fn (): CarbonImmutable => CarbonImmutable::now($timezone));
        $principal->method('rowLimit')->willReturn(5000);
        $principal->method('timeoutMs')->willReturn(3000);

        return $principal;
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

    private function item(string $tenantId, string $code, string $name, bool $default, bool $archived = false): void
    {
        $this->items[$code] = (string) Str::ulid();
        DB::table('contoh_a_m_barang')->insert([
            'id' => $this->items[$code], 'tenant_id' => $tenantId, 'kode' => $code, 'nama' => $name, 'bawaan' => $default,
            'deleted_at' => $archived ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sale(string $tenantId, string $item, string $unit, string $status, string $value, string $currency, string $date, ?string $recordedAt = null, ?string $paidAt = null): void
    {
        DB::table('contoh_a_tr_penjualan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'barang_id' => $this->items[$item],
            'legal_entity_id' => $tenantId === $this->tenantA ? $this->legalEntity : (string) Str::ulid(), 'org_unit_id' => $unit,
            'status' => $status, 'nilai' => $value, 'currency_code' => $currency, 'tanggal' => $date,
            'dicatat_pada' => $recordedAt, 'dibayar_pada' => $paidAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
