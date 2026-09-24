<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\SiteReport;
use ControlPlane\Sites\FinanceFeedHealth;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `finance_feed` di laporan agen: ringkasan feed posting finance dari Core di server klien (TODO feed posting finance
 * area 14), dari laporan yang diterima sampai tanda di halaman server klien.
 *
 * Bentuknya ditulis di skema `Report` pada `contracts/openapi-agent.yaml`. Yang dibuktikan di sini sisi admin.erp:
 * hanya jumlah dan waktu yang diterima, laporan agen lama tetap sah, dan penilaian "perlu perhatian" memakai ambang
 * yang satu. Agen membuktikan sisinya sendiri di `deploy/agent/tests`, terhadap skema yang sama.
 */
class FinanceFeedReportTest extends SiteTestCase
{
    private const SERVER_TIME = '2026-09-23T08:00:00+00:00';

    // ------------------------------------------------------------------ laporan yang diterima

    /** @return iterable<string, array{array<string, mixed>|null}> */
    public static function acceptedFeeds(): iterable
    {
        yield 'ringkasan lengkap' => [self::feed(['held' => 1, 'pending' => 3, 'posted' => 120, 'rejected' => 2, 'manual' => 4], '2026-09-20T03:00:00Z', '2026-09-23T07:55:00Z')];
        yield 'belum ada posting dan pull' => [self::feed([], null, null)];
        yield 'tidak terbaca dari Core' => [null];
        yield 'dengan push terakhir' => [[...self::feed(['pending' => 1, 'posted' => 9], '2026-09-23T07:00:00Z', null), 'last_pushed_at' => '2026-09-23T07:58:00Z']];
        yield 'push belum pernah diterima' => [[...self::feed(['pending' => 1], '2026-09-23T07:00:00Z', null), 'last_pushed_at' => null]];
    }

    /** @param  array<string, mixed>|null  $feed */
    #[DataProvider('acceptedFeeds')]
    public function test_a_finance_feed_in_its_shape_is_accepted_and_kept_as_sent(?array $feed): void
    {
        $site = $this->enrolledSite();

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['finance_feed' => $feed]))
            ->assertOk();

        $site->refresh();
        $this->assertIsArray($site->last_report);
        $this->assertArrayHasKey('finance_feed', $site->last_report);
        // `last_report` jsonb menyusun ulang urutan kunci; yang dibandingkan isinya, dengan tipe yang sama persis.
        $this->assertSame(self::sorted($feed), self::sorted($site->last_report['finance_feed']));
    }

    /**
     * Agen di server klien diperbarui sesudah konsol. Laporannya tanpa `finance_feed` harus tetap diterima, bukan
     * membuat seluruh server terlihat berhenti melapor.
     */
    public function test_a_report_from_an_agent_that_predates_the_finance_feed_is_still_accepted(): void
    {
        $site = $this->enrolledSite();
        $report = $this->report($site);
        $this->assertArrayNotHasKey('finance_feed', $report);

        $this->agent('POST', '/api/agent/v1/report', $site, $report)->assertOk();

        $this->assertIsArray($site->refresh()->last_report);
        $this->assertArrayNotHasKey('finance_feed', $site->last_report);
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedFeeds(): iterable
    {
        $sah = self::feed(['pending' => 1], '2026-09-20T03:00:00Z', '2026-09-23T07:55:00Z');

        yield 'nomor posting ikut' => [[...$sah, 'oldest_pending_posting_id' => 'AST-ACQ-0007']];
        yield 'isi jurnal ikut' => [[...$sah, 'journal_lines' => [['account' => 'Hutang Usaha', 'credit' => '555000000.00']]]];
        yield 'status di luar kontrak' => [[...$sah, 'counts' => [...$sah['counts'], 'draft' => 1]]];
        yield 'satu status hilang' => [[...$sah, 'counts' => array_diff_key($sah['counts'], ['manual' => true])]];
        yield 'jumlah negatif' => [[...$sah, 'counts' => [...$sah['counts'], 'held' => -1]]];
        yield 'jumlah berupa teks' => [[...$sah, 'counts' => [...$sah['counts'], 'rejected' => '2']]];
        yield 'jumlah pecahan' => [[...$sah, 'counts' => [...$sah['counts'], 'pending' => 1.5]]];
        yield 'counts tidak ada' => [array_diff_key($sah, ['counts' => true])];
        yield 'pull terakhir tidak disebut' => [array_diff_key($sah, ['last_pulled_at' => true])];
        yield 'waktu dengan zona lain' => [[...$sah, 'oldest_pending_at' => '2026-09-20T10:00:00+07:00']];
        yield 'waktu hanya tanggal' => [[...$sah, 'last_pulled_at' => '2026-09-23']];
        yield 'waktu push dengan zona lain' => [[...$sah, 'last_pushed_at' => '2026-09-23T14:58:00+07:00']];
        yield 'objek kosong' => [(object) []];
        yield 'larik kosong' => [[]];
        yield 'bukan objek' => ['sehat'];
    }

    /**
     * Daftar tertutup "data yang boleh keluar dari server klien" berlaku sampai ke dalam `finance_feed`: satu kunci
     * lain — nomor posting, isi jurnal — menolak seluruh laporan, dan tidak ada yang tersimpan.
     */
    #[DataProvider('refusedFeeds')]
    public function test_a_finance_feed_outside_its_shape_refuses_the_whole_report(mixed $feed): void
    {
        $site = $this->enrolledSite();

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['finance_feed' => $feed]))
            ->assertStatus(422)
            ->assertExactJson(['error' => 'report_invalid']);

        $this->assertNull($site->refresh()->last_report);
        $this->assertSame(0, SiteReport::query()->count());
    }

    /**
     * Angka feed bergerak bersama transaksi klinik, bukan bersama keadaan server. Laporan terakhir selalu membawa
     * angka terbarunya, tetapi perubahannya saja tidak melahirkan baris riwayat.
     */
    public function test_changes_in_the_finance_feed_alone_do_not_become_history(): void
    {
        $site = $this->enrolledSite();
        $baru = self::feed(['pending' => 1, 'posted' => 12], '2026-09-23T07:00:00Z', '2026-09-23T07:59:00Z');

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['finance_feed' => self::feed(['pending' => 2, 'posted' => 11], '2026-09-23T06:00:00Z', '2026-09-23T07:00:00Z')]))->assertOk();
        $this->travel(1)->minutes();
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['finance_feed' => $baru]))->assertOk();
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['finance_feed' => null]))->assertOk();

        $this->assertSame(1, SiteReport::query()->count());
        $this->assertIsArray($site->refresh()->last_report);
        $this->assertNull($site->last_report['finance_feed']);

        // Keadaan server yang berubah tetap menjadi riwayat.
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, ['release' => '0.2.0', 'finance_feed' => $baru]))->assertOk();
        $this->assertSame(2, SiteReport::query()->count());
    }

    // ------------------------------------------------------------------ halaman server klien

    /**
     * @return iterable<string, array{array<string, mixed>|null, string, list<string>, ?int}>
     */
    public static function judgedFeeds(): iterable
    {
        $lastPull = '2026-09-23T07:55:00Z';

        yield 'belum pernah melapor' => [null, 'not_reported', [], null];
        yield 'agen lama tanpa finance_feed' => [[], 'not_reported', [], null];
        yield 'tidak terbaca dari Core' => [['finance_feed' => null], 'unreadable', [], null];
        yield 'belum dipakai' => [['finance_feed' => self::feed([], null, null)], 'unused', [], null];
        yield 'posting manual saja tanpa pull' => [['finance_feed' => self::feed(['manual' => 3], null, null)], 'healthy', [], null];
        yield 'sehat' => [['finance_feed' => self::feed(['pending' => 2, 'posted' => 40], '2026-09-23T05:00:00Z', $lastPull)], 'healthy', [], 10800];
        yield 'ditolak' => [['finance_feed' => self::feed(['rejected' => 1, 'posted' => 40], null, $lastPull)], 'attention', ['rejected'], null];
        yield 'tertahan' => [['finance_feed' => self::feed(['held' => 2], null, null)], 'attention', ['held'], null];
        yield 'pending tepat sehari' => [['finance_feed' => self::feed(['pending' => 1], '2026-09-22T08:00:00Z', $lastPull)], 'healthy', [], 86400];
        yield 'pending lewat sehari' => [['finance_feed' => self::feed(['pending' => 1], '2026-09-22T07:59:59Z', $lastPull)], 'attention', ['pending_old'], 86401];
        yield 'semuanya' => [['finance_feed' => self::feed(['held' => 1, 'pending' => 5, 'rejected' => 2], '2026-09-15T00:00:00Z', null)], 'attention', ['rejected', 'held', 'pending_old'], 720000];
    }

    /**
     * Tanda merah (`attention`) bila ada posting ditolak atau tertahan, atau posting pending tertua lebih tua dari
     * ambangnya. Umur dihitung terhadap jam server klien di laporan yang sama: halaman yang dibuka sebulan kemudian
     * menilai angka yang sama.
     *
     * @param  array<string, mixed>|null  $overrides  null untuk situs yang belum pernah melapor
     * @param  list<string>  $alerts
     */
    #[DataProvider('judgedFeeds')]
    public function test_the_site_page_judges_the_finance_feed(?array $overrides, string $state, array $alerts, ?int $seconds): void
    {
        $site = $this->enrolledSite();

        if ($overrides !== null) {
            $site->forceFill(['last_report' => $this->report($site, ['server_time' => self::SERVER_TIME, ...$overrides])])->save();
        }

        $this->travelTo(now()->addDays(30));

        $this->actingAs($this->operator())->get("/situs/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/show')
                ->where('site.financeFeed.state', $state)
                ->where('site.financeFeed.alerts', $alerts)
                ->where('site.financeFeed.oldestPendingSeconds', $seconds)
                ->where('site.financeFeed.pendingAlertHours', FinanceFeedHealth::PENDING_ALERT_HOURS));
    }

    /** Laporan agen sampai ke halaman lewat jalur sungguhan: diterima, disimpan, lalu dinilai. */
    public function test_a_reported_problem_reaches_the_site_page(): void
    {
        $site = $this->enrolledSite();

        // Jam server tetap, bukan jam uji: umur posting pending terhadap jam uji akan melewati ambang sehari kemudian.
        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, [
            'server_time' => self::SERVER_TIME,
            'finance_feed' => self::feed(['pending' => 4, 'posted' => 90, 'rejected' => 1], '2026-09-23T06:30:00Z', '2026-09-23T07:59:00Z'),
        ]))->assertOk();

        $this->actingAs($this->operator())->get("/situs/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site.financeFeed.state', 'attention')
                ->where('site.financeFeed.alerts', ['rejected'])
                ->where('site.financeFeed.counts', ['held' => 0, 'pending' => 4, 'posted' => 90, 'rejected' => 1, 'manual' => 0])
                ->where('site.financeFeed.oldestPendingAt', '2026-09-23T06:30:00+00:00')
                ->where('site.financeFeed.oldestPendingSeconds', 5400)
                ->where('site.financeFeed.lastPulledAt', '2026-09-23T07:59:00+00:00')
                ->where('site.financeFeed.lastPushedAt', null)
                ->where('site.financeFeed.pushReported', false));
    }

    /**
     * Jam push terakhir hanya tampil bila Core dan agennya menyebutnya. Kunci yang tidak ada berarti tidak diketahui,
     * dan halaman tidak boleh membacanya sebagai "belum pernah ada push yang diterima".
     */
    public function test_the_last_accepted_push_reaches_the_site_page_only_when_reported(): void
    {
        $site = $this->enrolledSite();
        $feed = self::feed(['pending' => 1, 'posted' => 9], '2026-09-23T07:00:00Z', null);

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, [
            'server_time' => self::SERVER_TIME,
            'finance_feed' => [...$feed, 'last_pushed_at' => '2026-09-23T07:58:00Z'],
        ]))->assertOk();
        $this->actingAs($this->operator())->get("/situs/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site.financeFeed.lastPushedAt', '2026-09-23T07:58:00+00:00')
                ->where('site.financeFeed.pushReported', true));

        $this->agent('POST', '/api/agent/v1/report', $site, $this->report($site, [
            'server_time' => self::SERVER_TIME,
            'finance_feed' => [...$feed, 'last_pushed_at' => null],
        ]))->assertOk();
        $this->get("/situs/{$site->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site.financeFeed.lastPushedAt', null)
                ->where('site.financeFeed.pushReported', true));
    }

    /**
     * Ringkasan berbentuk skema `FinanceFeed` di kontrak.
     *
     * Status ditulis dari kontrak, bukan dari `FinanceFeedHealth::STATUSES`. Diturunkan dari konstanta itu, status
     * yang ditambahkan ke konsol tanpa kontraknya ikut masuk ke setiap laporan uji dan test tetap hijau — padahal di
     * server klien setiap laporan agen akan ditolak karena status yang tidak pernah dikirimnya.
     *
     * @param  array<string, int>  $counts  status yang tidak disebut bernilai nol
     * @return array{counts: array<string, int>, oldest_pending_at: ?string, last_pulled_at: ?string}
     */
    private static function feed(array $counts, ?string $oldestPendingAt, ?string $lastPulledAt): array
    {
        return [
            'counts' => [...['held' => 0, 'pending' => 0, 'posted' => 0, 'rejected' => 0, 'manual' => 0], ...$counts],
            'oldest_pending_at' => $oldestPendingAt,
            'last_pulled_at' => $lastPulledAt,
        ];
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(self::sorted(...), $value);
    }
}
