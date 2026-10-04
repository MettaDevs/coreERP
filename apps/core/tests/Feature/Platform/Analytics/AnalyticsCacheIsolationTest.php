<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Cache\QueryCache;
use App\Platform\Analytics\Datasets\CompiledDataset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Penjualan;
use Tests\Feature\Platform\Analytics\Support\SalesFixture;
use Tests\Feature\Platform\Analytics\Support\TestPrincipal;
use Tests\TestCase;

/**
 * Isolasi cache hasil analitik (area 9) — gate keamanan: hasil yang di-cache untuk satu jangkauan tidak pernah
 * terbaca oleh jangkauan lain (`docs/todo/analitik/keamanan.md`, ancaman *Lintas tenant lewat cache*).
 *
 * Dua lapis bukti:
 *
 * - **Kunci**: setiap komponen kunci — tenant, kode dan hash definisi dataset, query normal, sidik jari jangkauan,
 *   zona waktu, tanggal hari ini, batas baris — mengubah kunci sendirian, sementara komponen lain tetap. Principal
 *   bersidik jari tetap dipakai supaya tenant dan zona diuji tanpa ikut mengubah sidik jari.
 * - **Ujung ke ujung** lewat `RunQuery`: dua tenant, hibah berbeda, zona berbeda, dan pergantian hari di dalam
 *   masa TTL masing-masing menghitung angkanya sendiri; jangkauan yang sama berbagi hasil.
 *
 * Setiap komponen pernah dilihat merah dengan membuangnya dari `QueryCache::key()`; caranya ditulis di pull
 * request area 9. Pemisahan tenant punya tiga lapis — tenant di kunci, tenant di sidik jari jangkauan, dan
 * saringan `tenant_id` saat cache dibaca — sehingga test dua tenant baru merah bila ketiganya dibuang.
 */
class AnalyticsCacheIsolationTest extends TestCase
{
    use RefreshDatabase, SalesFixture;

    private string $tenantA;

    private string $tenantB;

    private string $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSalesModule();
        $this->tenantA = $this->salesTenant('Cache tenant A');
        $this->tenantB = $this->salesTenant('Cache tenant B');
        $this->legalEntity = (string) Str::ulid();
    }

    public function test_every_component_of_the_key_changes_the_key_on_its_own(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
        $cache = app(QueryCache::class);
        $dataset = self::dataset('contoh-a.penjualan', str_repeat('a', 64));
        $query = $this->salesQuery(['filters' => ['status' => ['terbit']]]);
        $principal = new TestPrincipal($this->tenantA, fixedFingerprint: 'sha256:jangkauan');
        $base = $cache->key($dataset, $query, $principal);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $base);

        $variants = [
            'tenant' => [$dataset, $query, new TestPrincipal($this->tenantB, fixedFingerprint: 'sha256:jangkauan')],
            'kode dataset' => [self::dataset('contoh-a.lain', str_repeat('a', 64)), $query, $principal],
            'hash definisi' => [self::dataset('contoh-a.penjualan', str_repeat('b', 64)), $query, $principal],
            'query' => [$dataset, $this->salesQuery(['filters' => ['status' => ['draf']]]), $principal],
            'sidik jari jangkauan' => [$dataset, $query, new TestPrincipal($this->tenantA, fixedFingerprint: 'sha256:jangkauan-lain')],
            // 03.00 UTC: 10.00 WIB dan 12.00 WIT, tanggal yang sama.
            'zona waktu' => [$dataset, $query, new TestPrincipal($this->tenantA, timezone: 'Asia/Jayapura', fixedFingerprint: 'sha256:jangkauan')],
            'batas baris' => [$dataset, $query, new TestPrincipal($this->tenantA, rowLimit: 100, fixedFingerprint: 'sha256:jangkauan')],
        ];
        foreach ($variants as $component => [$otherDataset, $otherQuery, $otherPrincipal]) {
            $this->assertNotSame($base, $cache->key($otherDataset, $otherQuery, $otherPrincipal), "Kunci cache tidak memuat {$component}.");
        }

        // Tanggal hari ini: principal dan semua yang lain sama, hanya harinya berganti.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 03:00:00', 'UTC'));
        $this->assertNotSame($base, $cache->key($dataset, $query, $principal), 'Kunci cache tidak memuat tanggal hari ini.');
        $this->travelTo(CarbonImmutable::parse('2026-10-04 16:00:00', 'UTC'));
        $this->assertSame($base, $cache->key($dataset, $query, $principal), 'Kunci cache berubah di dalam hari yang sama.');

        // Query yang setara berbagi kunci, dan pengguna lain dengan jangkauan sama juga.
        $equivalent = $this->salesQuery(['filters' => ['status' => ['terbit']], 'measures' => ['count']]);
        $this->assertSame($base, $cache->key($dataset, $equivalent, new TestPrincipal($this->tenantA, name: 'pengguna-lain', fixedFingerprint: 'sha256:jangkauan')));
    }

    public function test_two_tenants_running_the_same_query_never_read_each_others_result(): void
    {
        $unit = (string) Str::ulid();
        $this->sale($this->tenantA, $this->legalEntity, $unit, '10');
        $this->sale($this->tenantA, $this->legalEntity, $unit, '20');
        foreach (range(1, 5) as $i) {
            $this->sale($this->tenantB, (string) Str::ulid(), (string) Str::ulid(), '30');
        }

        $first = $this->analyse(new TestPrincipal($this->tenantA));
        $this->assertSame([2, false], [self::counted($first), $first->meta['cached']]);
        $other = $this->analyse(new TestPrincipal($this->tenantB));
        $this->assertSame([5, false], [self::counted($other), $other->meta['cached']], 'Tenant B membaca hasil cache tenant A.');

        // Masing-masing membaca cache miliknya sendiri.
        $cachedA = $this->analyse(new TestPrincipal($this->tenantA));
        $this->assertSame([2, true], [self::counted($cachedA), $cachedA->meta['cached']]);
        $again = $this->analyse(new TestPrincipal($this->tenantB));
        $this->assertSame([5, true], [self::counted($again), $again->meta['cached']]);

        $this->assertEqualsCanonicalizing([$this->tenantA, $this->tenantB], DB::table('analytics_query_cache')->pluck('tenant_id')->all());
    }

    public function test_a_different_reach_never_reads_a_cached_result_and_the_same_reach_shares_it(): void
    {
        $north = (string) Str::ulid();
        $south = (string) Str::ulid();
        $this->sale($this->tenantA, $this->legalEntity, $north, '10', status: 'terbit');
        foreach (range(1, 3) as $i) {
            $this->sale($this->tenantA, $this->legalEntity, $south, '20', status: 'draf');
        }

        $this->assertSame(1, self::counted($this->analyse(new TestPrincipal($this->tenantA, scope: self::grant($this->legalEntity, $north), name: 'utara-1'))));

        $southOnly = $this->analyse(new TestPrincipal($this->tenantA, scope: self::grant($this->legalEntity, $south), name: 'selatan'));
        $this->assertSame([3, false], [self::counted($southOnly), $southOnly->meta['cached']], 'Hibah berbeda membaca hasil cache hibah lain.');

        $noGrant = $this->analyse(new TestPrincipal($this->tenantA, scope: ['all' => false, 'scope_grants' => []], name: 'tanpa-hibah'));
        $this->assertSame([0, false], [self::counted($noGrant), $noGrant->meta['cached']], 'Principal tanpa hibah membaca hasil cache principal berhibah.');

        $locked = $this->analyse(new TestPrincipal($this->tenantA, locked: ['status' => ['draf']], name: 'terkunci'));
        $this->assertSame([3, false], [self::counted($locked), $locked->meta['cached']], 'Saringan terkunci tidak memisahkan cache.');

        $everything = $this->analyse(new TestPrincipal($this->tenantA, name: 'semua'));
        $this->assertSame([4, false], [self::counted($everything), $everything->meta['cached']]);
        $personal = $this->analyse(new TestPrincipal($this->tenantA, personalData: true, name: 'semua-data-pribadi'));
        $this->assertSame([4, false], [self::counted($personal), $personal->meta['cached']], 'Hak data pribadi tidak memisahkan cache, padahal label nama orang bergantung padanya.');

        // Pengguna lain dengan hibah yang sama memakai hasil yang sudah dihitung.
        $sameReach = $this->analyse(new TestPrincipal($this->tenantA, scope: self::grant($this->legalEntity, $north), name: 'utara-2'));
        $this->assertSame([1, true], [self::counted($sameReach), $sameReach->meta['cached']]);
    }

    public function test_the_time_zone_splits_the_cache_when_it_moves_rows_between_periods(): void
    {
        // 6 Oktober 03.00 UTC: tanggal yang sama di WIB dan WIT, jadi hanya zonanya yang berbeda.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 03:00:00', 'UTC'));
        // 4 Oktober 16.30 UTC = 23.30 WIB tanggal 4, tetapi 01.30 WIT tanggal 5.
        DB::table('contoh_a_tr_penjualan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantA, 'barang_id' => (string) Str::ulid(),
            'legal_entity_id' => $this->legalEntity, 'org_unit_id' => (string) Str::ulid(), 'status' => 'terbit', 'nilai' => '10',
            'currency_code' => 'IDR', 'tanggal' => '2026-10-04', 'dicatat_pada' => '2026-10-04 16:30:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $byDay = ['dimensions' => [['field' => 'dicatat_pada', 'granularity' => 'day']], 'filters' => ['status' => ['terbit']]];

        $jakarta = $this->analyse(new TestPrincipal($this->tenantA, timezone: 'Asia/Jakarta'), $byDay);
        $this->assertSame('2026-10-04', $jakarta->rows[0]['dicatat_pada'] ?? null);

        $jayapura = $this->analyse(new TestPrincipal($this->tenantA, timezone: 'Asia/Jayapura'), $byDay);
        $this->assertFalse($jayapura->meta['cached'], 'Zona berbeda membaca hasil cache zona lain.');
        $this->assertSame('2026-10-05', $jayapura->rows[0]['dicatat_pada'] ?? null);
    }

    public function test_a_new_day_inside_the_ttl_recomputes_relative_ranges(): void
    {
        // 23.58 WIB; TTL bawaan lima menit masih berlaku dua menit kemudian, saat hari sudah berganti.
        $this->travelTo(CarbonImmutable::parse('2026-10-04 23:58:00', 'Asia/Jakarta'));
        $unit = (string) Str::ulid();
        $this->sale($this->tenantA, $this->legalEntity, $unit, '10', date: '2026-10-04');
        $this->sale($this->tenantA, $this->legalEntity, $unit, '10', date: '2026-10-05');
        $this->sale($this->tenantA, $this->legalEntity, $unit, '10', date: '2026-10-05');
        $today = ['time_range' => ['range' => '@today']];
        $principal = new TestPrincipal($this->tenantA, timezone: 'Asia/Jakarta');

        $this->assertSame(1, self::counted($this->analyse($principal, $today)));
        $this->assertTrue($this->analyse($principal, $today)->meta['cached']);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 00:01:00', 'Asia/Jakarta'));
        $tomorrow = $this->analyse($principal, $today);
        $this->assertSame([2, false], [self::counted($tomorrow), $tomorrow->meta['cached']], '"Hari ini" yang kemarin terbaca dari cache.');
    }

    /** Dataset minimal untuk test kunci: kunci hanya membaca kode, hash definisi, dan kebijakannya. */
    private static function dataset(string $code, string $hash): CompiledDataset
    {
        return new CompiledDataset(
            code: $code, caption: 'Penjualan', moduleId: 'contoh-a', version: 1, model: Penjualan::class,
            table: 'penjualan', permission: 'contoh-a.penjualan.read', policy: null, fields: [], measures: [],
            times: [], defaultTime: null, hash: $hash,
        );
    }
}
