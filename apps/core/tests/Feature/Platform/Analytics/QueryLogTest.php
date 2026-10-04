<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Retention\Support\RetentionPolicies;
use App\Platform\Retention\Support\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Platform\Analytics\Support\SalesFixture;
use Tests\Feature\Platform\Analytics\Support\TestPrincipal;
use Tests\TestCase;

/**
 * Log query analitik (area 9, `docs/todo/analitik/keamanan.md` bagian *Log query*): satu baris per query yang
 * sampai ke engine — berhasil, dari cache, atau ditolak — dengan nilai saringan field data pribadi disamarkan
 * apa pun hak orang yang menjalankannya, dan kebijakan retensi `analytics_query_log` (bawaan 90 hari, minimum 7,
 * PQ-05).
 *
 * Setiap penjaga pernah dilihat merah dengan merusak penangkalnya; caranya ditulis di pull request area 9.
 */
class QueryLogTest extends TestCase
{
    use RefreshDatabase, SalesFixture;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSalesModule();
        $this->tenant = $this->salesTenant('Log analitik');
        $this->sale($this->tenant, (string) Str::ulid(), (string) Str::ulid(), '10', buyer: 'Budi Santoso');
        $this->sale($this->tenant, (string) Str::ulid(), (string) Str::ulid(), '20', buyer: 'Siti Aminah', status: 'draf');
    }

    public function test_each_query_leaves_one_row_with_its_outcome(): void
    {
        $principal = new TestPrincipal($this->tenant, name: 'membership:42');
        $input = ['dimensions' => ['status'], 'filters' => ['status' => ['terbit', 'draf']], 'limit' => 1];

        $this->analyse($principal, $input, source: QueryLog::SOURCE_WIDGET);
        $this->analyse($principal, $input, source: QueryLog::SOURCE_WIDGET);

        $rows = DB::table('analytics_query_log')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $first = $rows[0];
        $this->assertSame($this->tenant, $first->tenant_id);
        $this->assertSame('uji:membership:42', $first->principal);
        $this->assertSame('widget', $first->source);
        $this->assertSame(self::SALES, $first->dataset_code);
        $this->assertSame(1, (int) $first->dataset_version);
        $this->assertSame('success', $first->status);
        $this->assertNull($first->error_code);
        $this->assertSame(1, (int) $first->row_count);
        $this->assertTrue((bool) $first->truncated);
        $this->assertFalse((bool) $first->cached);
        $this->assertGreaterThanOrEqual(0, (int) $first->duration_ms);
        $this->assertSame(['draf', 'terbit'], json_decode((string) $first->query, true)['filters']['status']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $first->query_hash);
        // Yang kedua dari cache; query-nya sama, jadi hash-nya sama.
        $this->assertTrue((bool) $rows[1]->cached);
        $this->assertSame($first->query_hash, $rows[1]->query_hash);
    }

    public function test_refused_queries_are_logged_with_their_error_code(): void
    {
        $refusals = [
            [new TestPrincipal($this->tenant, permitted: false), ['dataset' => self::SALES, 'measures' => ['count']], 'analytics.dataset_forbidden', self::SALES],
            [new TestPrincipal($this->tenant), ['dataset' => self::SALES, 'measures' => ['tidak_ada']], 'analytics.field_unknown', self::SALES],
            [new TestPrincipal($this->tenant), ['dataset' => 'contoh-a.tidak-ada', 'measures' => ['count']], 'analytics.dataset_unknown', 'contoh-a.tidak-ada'],
        ];

        foreach ($refusals as [$principal, $input, $code, $dataset]) {
            try {
                app(RunQuery::class)->handle($principal, $this->parsed($input));
                $this->fail("Query seharusnya ditolak dengan {$code}.");
            } catch (AnalyticsQueryException $e) {
                $this->assertSame($code, $e->errorCode);
            }

            $row = DB::table('analytics_query_log')->orderByDesc('created_at')->orderByDesc('id')->first();
            $this->assertNotNull($row);
            $this->assertSame(['failed', $code, $dataset], [$row->status, $row->error_code, $row->dataset_code]);
            $this->assertNull($row->row_count);
        }
    }

    public function test_filter_values_on_personal_fields_are_masked_whoever_runs_the_query(): void
    {
        $input = ['filters' => ['nama_pembeli' => 'Budi*', 'dicatat_oleh_user_id' => ['7'], 'status' => ['terbit']]];

        // Berhak data pribadi: query berjalan, nilai nama tetap tidak masuk log.
        $this->analyse(new TestPrincipal($this->tenant, personalData: true), $input);
        // Tanpa hak: query ditolak, dan nilai yang dicoba juga tidak masuk log.
        try {
            $this->analyse(new TestPrincipal($this->tenant), $input);
            $this->fail('Saringan nama pembeli seharusnya ditolak tanpa hak data pribadi.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('analytics.field_personal_data', $e->errorCode);
        }

        $this->assertSame(['success', 'failed'], DB::table('analytics_query_log')->orderBy('created_at')->orderBy('id')->pluck('status')->all());
        $logged = DB::table('analytics_query_log')->pluck('query')->map(static fn (mixed $query): array => (array) json_decode((string) $query, true))->all();
        $this->assertCount(2, $logged);
        foreach ($logged as $query) {
            // jsonb menyusun ulang kunci objek; isinya yang dibandingkan.
            $filters = (array) $query['filters'];
            ksort($filters);
            $this->assertSame(['dicatat_oleh_user_id' => QueryLog::MASK, 'nama_pembeli' => QueryLog::MASK, 'status' => ['terbit']], $filters);
        }
        $raw = DB::table('analytics_query_log')->selectRaw('string_agg(query::text, \' \') as text')->value('text');
        $this->assertStringNotContainsString('Budi', (string) $raw);

        // Hash dihitung dari bentuk tersamar, supaya tidak dapat dipakai menebak nilai aslinya.
        $original = hash('sha256', (string) json_encode($this->salesQuery($input)->normalized(), JSON_THROW_ON_ERROR));
        $this->assertNotContains($original, DB::table('analytics_query_log')->pluck('query_hash')->all());
    }

    public function test_without_a_known_dataset_every_filter_value_and_the_time_range_are_masked(): void
    {
        $query = $this->parsed(['dataset' => 'contoh-a.tidak-ada', 'measures' => ['count'], 'filters' => ['apa_saja' => 'rahasia'], 'time_range' => ['range' => '2026-01-01..2026-01-31']]);

        $masked = QueryLog::masked($query, null);

        $this->assertSame(['apa_saja' => QueryLog::MASK], $masked['filters']);
        $this->assertSame(['field' => null, 'range' => QueryLog::MASK], $masked['time_range']);
        // Field waktu biasa pada dataset yang dikenal tidak disamarkan.
        $known = QueryLog::masked($this->salesQuery(['time_range' => ['range' => '@this_month']]), $this->salesDataset());
        $this->assertSame(['field' => null, 'range' => '@this_month'], $known['time_range']);
    }

    public function test_the_log_is_retained_ninety_days_by_default_and_never_less_than_seven(): void
    {
        $policy = RetentionPolicies::find('analytics_query_log');
        $this->assertSame(['analytics_query_log', 'created_at', 7, 90, true], [$policy->table, $policy->dateColumn, $policy->minimumDays, $policy->defaultDays(), $policy->tenantConfigurable]);

        $this->analyse(new TestPrincipal($this->tenant));
        $this->analyse(new TestPrincipal($this->tenant, name: 'lama'));
        DB::table('analytics_query_log')->where('principal', 'uji:lama')->update(['created_at' => now()->subDays(91)]);
        $other = $this->salesTenant('Log tenant lain');
        $this->analyse(new TestPrincipal($other, name: 'lama-lain'));
        DB::table('analytics_query_log')->where('principal', 'uji:lama-lain')->update(['created_at' => now()->subDays(91)]);

        $this->assertSame(1, app(RetentionService::class)->apply('analytics_query_log', $this->tenant));
        $this->assertSame(['uji:uji'], DB::table('analytics_query_log')->where('tenant_id', $this->tenant)->pluck('principal')->all());
        $this->assertSame(1, DB::table('analytics_query_log')->where('tenant_id', $other)->count(), 'Retensi satu tenant menyentuh log tenant lain.');

        // Admin tenant boleh memendekkan masa simpan sampai minimum, tidak kurang.
        app(RetentionService::class)->save($this->tenant, $policy, true, 3);
        $this->assertSame(7, app(RetentionService::class)->daysFor('analytics_query_log', $this->tenant));
    }

    /** @param array<string, mixed> $input */
    private function parsed(array $input): AnalyticsQuery
    {
        return (new QueryNormalizer)->normalize((new QueryParser)->parse($input));
    }
}
