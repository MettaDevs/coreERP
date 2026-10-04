<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\ResultSet;
use App\Platform\Modules\Contracts\TenantRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Platform\Analytics\Support\SalesFixture;
use Tests\Feature\Platform\Analytics\Support\TestPrincipal;
use Tests\TestCase;

/**
 * Perilaku cache hasil analitik (area 9, `docs/todo/analitik/kinerja-dan-uji-beban.md` bagian *Cache*): TTL per
 * pemanggil beserta minimum dan "tanpa cache", Muat ulang yang melewati cache, isi terkompres di tabel database
 * tenant, batas ukuran, pembersihan saat baca dan tulis, dan kunci serbuan. Isolasi antarjangkauan diuji
 * `AnalyticsCacheIsolationTest`.
 *
 * Setiap penjaga pernah dilihat merah dengan merusak penangkalnya; caranya ditulis di pull request area 9.
 */
class QueryCacheTest extends TestCase
{
    use RefreshDatabase, SalesFixture;

    private string $tenant;

    private string $legalEntity;

    private string $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSalesModule();
        $this->tenant = $this->salesTenant('Cache perilaku');
        $this->legalEntity = (string) Str::ulid();
        $this->unit = (string) Str::ulid();
        $this->sale($this->tenant, $this->legalEntity, $this->unit, '10');
        $this->travelTo(CarbonImmutable::parse('2026-10-04 09:00:00', 'Asia/Jakarta'));
    }

    public function test_a_result_is_served_from_cache_until_its_ttl_runs_out(): void
    {
        $principal = new TestPrincipal($this->tenant);

        $first = $this->analyse($principal);
        $this->assertSame([1, false], [self::counted($first), $first->meta['cached']]);

        // Data berubah, tetapi hasil yang sama persis dalam masa TTL bawaan (300 detik) dibaca dari cache.
        $this->sale($this->tenant, $this->legalEntity, $this->unit, '20');
        $this->travel(299)->seconds();
        $cached = $this->analyse($principal);
        $this->assertSame([1, true], [self::counted($cached), $cached->meta['cached']]);
        // Waktu hitung yang tampil di layar adalah waktu hasil itu dihitung, bukan waktu dibaca.
        $this->assertSame($first->meta['generated_at'], $cached->meta['generated_at']);
        $this->assertSame($first->toArray()['columns'], $cached->toArray()['columns']);

        $this->travel(2)->seconds();
        $fresh = $this->analyse($principal);
        $this->assertSame([2, false], [self::counted($fresh), $fresh->meta['cached']]);
        // Baris yang kedaluwarsa dihapus saat terbaca, lalu ditulis ulang: tetap satu baris untuk kunci ini.
        $this->assertSame(1, DB::table('analytics_query_cache')->count());
    }

    public function test_ttl_zero_means_no_cache_and_a_short_ttl_is_raised_to_the_minimum(): void
    {
        $principal = new TestPrincipal($this->tenant);

        $this->analyse($principal, ttl: 0);
        $this->assertFalse($this->analyse($principal, ttl: 0)->meta['cached']);
        $this->assertSame(0, DB::table('analytics_query_cache')->count(), 'TTL 0 tetap menulis cache.');

        $this->analyse($principal, ttl: 10);
        $row = DB::table('analytics_query_cache')->sole();
        $this->assertSame(60, (int) CarbonImmutable::parse($row->updated_at)->diffInSeconds(CarbonImmutable::parse($row->expires_at)));
        $this->travel(30)->seconds();
        $this->assertTrue($this->analyse($principal, ttl: 10)->meta['cached'], 'TTL 10 detik tidak dinaikkan ke minimum 60 detik.');
        // Sesudah masa berlakunya habis, baris itu tidak terbaca lagi, walau pembacanya ber-TTL lebih panjang.
        $this->travel(31)->seconds();
        $this->assertFalse($this->analyse($principal, ttl: 600)->meta['cached'], 'Baris kedaluwarsa masih terbaca.');

        $this->assertSame(300, app(QueryCache::class)->ttl(null));
        $this->assertSame(600, app(QueryCache::class)->ttl(600));
        $this->assertSame(0, app(QueryCache::class)->ttl(-5));
    }

    public function test_a_reader_never_accepts_a_result_older_than_its_own_ttl(): void
    {
        $principal = new TestPrincipal($this->tenant);
        $this->analyse($principal, ttl: 600);
        $this->sale($this->tenant, $this->legalEntity, $this->unit, '20');
        $this->travel(120)->seconds();

        // Ditulis dengan TTL sepuluh menit, tetapi penjelajah hanya mau hasil satu menit terakhir.
        $explore = $this->analyse($principal, ttl: 60);
        $this->assertSame([2, false], [self::counted($explore), $explore->meta['cached']]);
        // Hasil yang baru dihitung itu menimpa barisnya, dan pembaca berTTL panjang ikut memakainya.
        $widget = $this->analyse($principal, ttl: 600);
        $this->assertSame([2, true], [self::counted($widget), $widget->meta['cached']]);
    }

    public function test_reload_recomputes_without_reading_the_cache_and_replaces_it(): void
    {
        $principal = new TestPrincipal($this->tenant);
        $this->analyse($principal);
        $this->sale($this->tenant, $this->legalEntity, $this->unit, '20');
        $this->travel(10)->seconds();

        $reloaded = $this->analyse($principal, refresh: true);
        $this->assertSame([2, false], [self::counted($reloaded), $reloaded->meta['cached']]);

        $after = $this->analyse($principal);
        $this->assertSame([2, true], [self::counted($after), $after->meta['cached']]);
        $this->assertSame($reloaded->meta['generated_at'], $after->meta['generated_at']);
        $this->assertSame(1, DB::table('analytics_query_cache')->count());
    }

    public function test_the_payload_is_compressed_in_the_tenant_table_and_large_results_are_not_kept(): void
    {
        $principal = new TestPrincipal($this->tenant);
        $result = $this->analyse($principal, ['dimensions' => ['status'], 'measures' => ['count', 'nilai']]);

        $row = DB::table('analytics_query_cache')->select(['tenant_id', 'dataset_code', 'size_bytes'])->selectRaw('octet_length(payload) as stored')->selectRaw("encode(payload, 'base64') as payload64")->sole();
        $this->assertSame($this->tenant, $row->tenant_id);
        $this->assertSame(self::SALES, $row->dataset_code);
        $this->assertSame((int) $row->stored, (int) $row->size_bytes);
        $decoded = json_decode((string) gzdecode((string) base64_decode((string) $row->payload64, true)), true);
        $this->assertIsArray($decoded);
        $this->assertSame($result->rows, $decoded['rows']);

        // Batas ukuran 0 KB: tidak ada hasil yang cukup kecil untuk disimpan.
        DB::table('analytics_query_cache')->delete();
        config(['analytics.cache.max_payload_kb' => 0]);
        $this->analyse($principal, ['dimensions' => ['status'], 'measures' => ['count', 'nilai']]);
        $this->assertSame(0, DB::table('analytics_query_cache')->count(), 'Hasil yang melebihi batas ukuran tetap disimpan.');
    }

    public function test_writing_clears_at_most_a_hundred_expired_rows(): void
    {
        $this->expiredRows($this->tenant, 101);

        $this->analyse(new TestPrincipal($this->tenant));

        $this->assertSame(1, DB::table('analytics_query_cache')->where('tenant_id', $this->tenant)->where('expires_at', '<=', now())->count(), 'Pembersihan tidak dibatasi 100 baris per penulisan.');
        $this->assertSame(1, DB::table('analytics_query_cache')->where('tenant_id', $this->tenant)->where('expires_at', '>', now())->count());
    }

    public function test_writing_never_clears_another_tenants_rows(): void
    {
        // Lima baris kedaluwarsa, di bawah batas 100: pembersihan yang lupa menyaring tenant menghapus semuanya.
        $other = $this->salesTenant('Cache tenant lain');
        $this->expiredRows($other, 2);
        $this->expiredRows($this->tenant, 3);

        $this->analyse(new TestPrincipal($this->tenant));

        $this->assertSame(0, DB::table('analytics_query_cache')->where('tenant_id', $this->tenant)->where('expires_at', '<=', now())->count());
        $this->assertSame(2, DB::table('analytics_query_cache')->where('tenant_id', $other)->count(), 'Pembersihan menyentuh baris tenant lain.');
    }

    public function test_a_second_caller_waits_for_the_first_computation_and_reads_its_result(): void
    {
        Sleep::fake(syncWithCarbon: true);
        [$dataset, $query, $principal, $key] = $this->subject();
        $first = Cache::lock('analytics:compute:'.$this->tenant.':'.$key, 30);
        $this->assertTrue($first->get());

        // Pemanggil pertama selesai menulis saat pemanggil kedua sedang menunggu.
        $marker = $this->marked('sha256:hasil-pemanggil-pertama');
        Sleep::whenFakingSleep(function () use ($dataset, $query, $principal, $marker): void {
            if (DB::table('analytics_query_cache')->count() === 0) {
                $this->inTenant(fn (): ResultSet => app(QueryCache::class)->remember($dataset, $query, $principal, 300, true, static fn (): ResultSet => $marker));
            }
        });

        $result = $this->inTenant(fn (): ResultSet => app(QueryCache::class)->remember($dataset, $query, $principal, 300, false, static function (): ResultSet {
            throw new LogicException('Pemanggil kedua menghitung ulang padahal pemanggil pertama sedang menghitung.');
        }));

        $this->assertSame('sha256:hasil-pemanggil-pertama', $result->meta['query_hash']);
        $this->assertTrue($result->meta['cached']);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_caller_computes_itself_when_the_first_one_ends_without_a_result_or_takes_too_long(): void
    {
        Sleep::fake(syncWithCarbon: true);
        [$dataset, $query, $principal, $key] = $this->subject();
        $first = Cache::lock('analytics:compute:'.$this->tenant.':'.$key, 30);
        $this->assertTrue($first->get());

        // Pemanggil pertama berhenti tanpa menyimpan (hasil terlalu besar, atau gagal): kunci lepas, cache tetap kosong.
        Sleep::whenFakingSleep(static fn () => $first->release());
        $computed = 0;
        $compute = function () use (&$computed): ResultSet {
            $computed++;

            return $this->marked('sha256:dihitung-sendiri');
        };
        $this->inTenant(fn (): ResultSet => app(QueryCache::class)->remember($dataset, $query, $principal, 300, false, $compute));
        $this->assertSame(1, $computed);
        Sleep::assertSleptTimes(1);

        // Pemanggil pertama tidak pernah selesai: sesudah batas waktu principal, pemanggil ini menghitung sendiri.
        DB::table('analytics_query_cache')->delete();
        Sleep::fake(syncWithCarbon: true);
        $stuck = Cache::lock('analytics:compute:'.$this->tenant.':'.$key, 3600);
        $this->assertTrue($stuck->get());
        $this->inTenant(fn (): ResultSet => app(QueryCache::class)->remember($dataset, $query, $principal, 300, false, $compute));
        $this->assertSame(2, $computed);
        // Batas tunggunya batas waktu principal (3 detik), dengan jeda 200 milidetik.
        Sleep::assertSleptTimes(15);
    }

    public function test_the_first_caller_computes_without_waiting(): void
    {
        Sleep::fake();
        [$dataset, $query, $principal] = $this->subject();

        $this->inTenant(fn (): ResultSet => app(QueryCache::class)->remember($dataset, $query, $principal, 300, false, fn (): ResultSet => $this->marked('sha256:pertama')));

        Sleep::assertNeverSlept();
        $this->assertSame(1, DB::table('analytics_query_cache')->count());
    }

    /** @return array{0: CompiledDataset, 1: AnalyticsQuery, 2: TestPrincipal, 3: string} */
    private function subject(): array
    {
        $dataset = $this->salesDataset();
        $query = $this->salesQuery();
        $principal = new TestPrincipal($this->tenant);

        return [$dataset, $query, $principal, app(QueryCache::class)->key($dataset, $query, $principal)];
    }

    /** Hasil sungguhan yang ditandai lewat `meta.query_hash`, supaya asalnya dapat dikenali. */
    private function marked(string $hash): ResultSet
    {
        $result = $this->analyse(new TestPrincipal($this->tenant, name: 'penanda'), ['filters' => ['status' => ['batal']]], ttl: 0);

        return new ResultSet($result->columns, $result->rows, $result->totals, ['query_hash' => $hash] + $result->meta);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function inTenant(callable $action): mixed
    {
        return app(TenantRunner::class)->runFor($this->tenant, $action);
    }

    private function expiredRows(string $tenant, int $count): void
    {
        DB::statement(
            "insert into analytics_query_cache (id, tenant_id, cache_key, dataset_code, payload, size_bytes, expires_at, created_at, updated_at)
             select lower(substr(md5(random()::text), 1, 26)), ?, md5(random()::text) || md5(random()::text), ?, decode('00', 'hex'), 1, ?, ?, ?
             from generate_series(1, ?)",
            [$tenant, self::SALES, now()->subMinute(), now()->subMinutes(10), now()->subMinutes(10), $count],
        );
    }
}
