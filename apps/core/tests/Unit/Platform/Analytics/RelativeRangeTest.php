<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\RelativeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Token rentang waktu relatif (area 2, `docs/todo/analitik/mesin-query.md` bagian *Rentang waktu relatif*),
 * tanpa database. Setiap kasus memberi `RelativeRange` waktu sekarang yang sudah berzona, seperti
 * `AnalyticsPrincipal::now()`, dan memeriksa rentang tanggal yang dihasilkan.
 *
 * Tiga zona Indonesia diuji di tiga batas yang paling sering salah: pergantian tahun (31 Desember 23.30),
 * tahun kabisat (29 Februari 2028), dan pergantian minggu (Minggu malam ke Senin pagi). Tanggal harapan
 * dihitung dari kalender, bukan dari kelas yang diuji.
 */
class RelativeRangeTest extends TestCase
{
    private const ZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Kamis 15 Oktober 2026, contoh yang dipakai tabel token di halaman rancangan.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function documentedExample(): iterable
    {
        yield '@today' => ['@today', '2026-10-15..2026-10-15'];
        yield '@yesterday' => ['@yesterday', '2026-10-14..2026-10-14'];
        yield '@this_week' => ['@this_week', '2026-10-12..2026-10-18'];
        yield '@last_week' => ['@last_week', '2026-10-05..2026-10-11'];
        yield '@this_month' => ['@this_month', '2026-10-01..2026-10-31'];
        yield '@last_month' => ['@last_month', '2026-09-01..2026-09-30'];
        yield '@this_quarter' => ['@this_quarter', '2026-10-01..2026-12-31'];
        yield '@last_quarter' => ['@last_quarter', '2026-07-01..2026-09-30'];
        yield '@this_year' => ['@this_year', '2026-01-01..2026-12-31'];
        yield '@last_year' => ['@last_year', '2025-01-01..2025-12-31'];
        yield '@last_7_days' => ['@last_7_days', '2026-10-09..2026-10-15'];
        yield '@last_30_days' => ['@last_30_days', '2026-09-16..2026-10-15'];
        yield '@last_90_days' => ['@last_90_days', '2026-07-18..2026-10-15'];
        yield '@last_12_months' => ['@last_12_months', '2025-11-01..2026-10-31'];
        yield '@year_to_date' => ['@year_to_date', '2026-01-01..2026-10-15'];
        yield '@month_to_date' => ['@month_to_date', '2026-10-01..2026-10-15'];
    }

    #[DataProvider('documentedExample')]
    public function test_tokens_match_the_documented_example_in_every_zone(string $token, string $expected): void
    {
        foreach (self::ZONES as $zone) {
            $now = CarbonImmutable::parse('2026-10-15 12:00:00', $zone);

            $this->assertSame($expected, RelativeRange::expression($token, $now), "{$token} di {$zone}");
        }
    }

    /**
     * Kamis 31 Desember 2026 pukul 23.30 waktu setempat: hari terakhir bulan, kuartal, dan tahun.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function endOfYear(): iterable
    {
        yield '@today' => ['@today', '2026-12-31..2026-12-31'];
        yield '@yesterday' => ['@yesterday', '2026-12-30..2026-12-30'];
        // Minggu ini melewati pergantian tahun: Senin 28 Desember sampai Minggu 3 Januari.
        yield '@this_week' => ['@this_week', '2026-12-28..2027-01-03'];
        yield '@last_week' => ['@last_week', '2026-12-21..2026-12-27'];
        yield '@this_month' => ['@this_month', '2026-12-01..2026-12-31'];
        yield '@last_month' => ['@last_month', '2026-11-01..2026-11-30'];
        yield '@this_quarter' => ['@this_quarter', '2026-10-01..2026-12-31'];
        yield '@last_quarter' => ['@last_quarter', '2026-07-01..2026-09-30'];
        yield '@this_year' => ['@this_year', '2026-01-01..2026-12-31'];
        yield '@last_year' => ['@last_year', '2025-01-01..2025-12-31'];
        yield '@last_7_days' => ['@last_7_days', '2026-12-25..2026-12-31'];
        yield '@last_30_days' => ['@last_30_days', '2026-12-02..2026-12-31'];
        yield '@last_90_days' => ['@last_90_days', '2026-10-03..2026-12-31'];
        yield '@last_12_months' => ['@last_12_months', '2026-01-01..2026-12-31'];
        yield '@year_to_date' => ['@year_to_date', '2026-01-01..2026-12-31'];
        yield '@month_to_date' => ['@month_to_date', '2026-12-01..2026-12-31'];
    }

    #[DataProvider('endOfYear')]
    public function test_tokens_on_new_years_eve_at_2330_local_time(string $token, string $expected): void
    {
        foreach (self::ZONES as $zone) {
            $now = CarbonImmutable::parse('2026-12-31 23:30:00', $zone);

            $this->assertSame($expected, RelativeRange::expression($token, $now), "{$token} di {$zone}");
        }
    }

    /**
     * Selasa 29 Februari 2028 pukul 23.30 waktu setempat: hari kabisat yang menutup bulan dan kuartal.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function leapDay(): iterable
    {
        yield '@today' => ['@today', '2028-02-29..2028-02-29'];
        yield '@yesterday' => ['@yesterday', '2028-02-28..2028-02-28'];
        yield '@this_week' => ['@this_week', '2028-02-28..2028-03-05'];
        yield '@last_week' => ['@last_week', '2028-02-21..2028-02-27'];
        yield '@this_month' => ['@this_month', '2028-02-01..2028-02-29'];
        yield '@last_month' => ['@last_month', '2028-01-01..2028-01-31'];
        yield '@this_quarter' => ['@this_quarter', '2028-01-01..2028-03-31'];
        yield '@last_quarter' => ['@last_quarter', '2027-10-01..2027-12-31'];
        yield '@this_year' => ['@this_year', '2028-01-01..2028-12-31'];
        yield '@last_year' => ['@last_year', '2027-01-01..2027-12-31'];
        yield '@last_7_days' => ['@last_7_days', '2028-02-23..2028-02-29'];
        yield '@last_30_days' => ['@last_30_days', '2028-01-31..2028-02-29'];
        yield '@last_90_days' => ['@last_90_days', '2027-12-02..2028-02-29'];
        yield '@last_12_months' => ['@last_12_months', '2027-03-01..2028-02-29'];
        yield '@year_to_date' => ['@year_to_date', '2028-01-01..2028-02-29'];
        yield '@month_to_date' => ['@month_to_date', '2028-02-01..2028-02-29'];
    }

    #[DataProvider('leapDay')]
    public function test_tokens_on_leap_day_at_2330_local_time(string $token, string $expected): void
    {
        foreach (self::ZONES as $zone) {
            $now = CarbonImmutable::parse('2028-02-29 23:30:00', $zone);

            $this->assertSame($expected, RelativeRange::expression($token, $now), "{$token} di {$zone}");
        }
    }

    public function test_the_day_after_the_leap_day_still_sees_february_as_29_days_long(): void
    {
        $now = CarbonImmutable::parse('2028-03-01 00:30:00', 'Asia/Jayapura');

        $this->assertSame('2028-02-01..2028-02-29', RelativeRange::expression('@last_month', $now));
        $this->assertSame('2028-02-29..2028-02-29', RelativeRange::expression('@yesterday', $now));
    }

    public function test_the_day_after_a_common_years_february_ends_on_the_28th(): void
    {
        $now = CarbonImmutable::parse('2027-03-01 00:30:00', 'Asia/Jakarta');

        $this->assertSame('2027-02-01..2027-02-28', RelativeRange::expression('@last_month', $now));
        // Sebelas bulan sebelum Maret 2027 adalah April 2026; dua belas bulan penuh termasuk bulan ini.
        $this->assertSame('2026-04-01..2027-03-31', RelativeRange::expression('@last_12_months', $now));
    }

    public function test_the_week_turns_over_between_sunday_night_and_monday_morning(): void
    {
        $sunday = CarbonImmutable::parse('2026-10-18 23:30:00', 'Asia/Jakarta');
        $monday = CarbonImmutable::parse('2026-10-19 00:30:00', 'Asia/Jakarta');

        $this->assertSame('2026-10-12..2026-10-18', RelativeRange::expression('@this_week', $sunday));
        $this->assertSame('2026-10-05..2026-10-11', RelativeRange::expression('@last_week', $sunday));
        $this->assertSame('2026-10-19..2026-10-25', RelativeRange::expression('@this_week', $monday));
        $this->assertSame('2026-10-12..2026-10-18', RelativeRange::expression('@last_week', $monday));
    }

    /**
     * Satu saat yang sama jatuh di hari berbeda bagi tiga zona: Jakarta tertinggal satu jam dari Makassar
     * dan dua jam dari Jayapura. Di sinilah "tahun ini" berbeda untuk pengguna yang berbeda zona.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function sameInstantInDifferentZones(): iterable
    {
        // 31 Desember 16.30 UTC: 23.30 di Jakarta, sudah 1 Januari 00.30 di Makassar dan 01.30 di Jayapura.
        yield 'Jakarta 23.30 masih 2026' => ['2026-12-31 16:30:00', 'Asia/Jakarta', '2026-01-01..2026-12-31'];
        yield 'Makassar 00.30 sudah 2027' => ['2026-12-31 16:30:00', 'Asia/Makassar', '2027-01-01..2027-12-31'];
        yield 'Jayapura 01.30 sudah 2027' => ['2026-12-31 16:30:00', 'Asia/Jayapura', '2027-01-01..2027-12-31'];
        // Satu jam lebih awal: Makassar masih 23.30, hanya Jayapura yang sudah berganti tahun.
        yield 'Jakarta 22.30 masih 2026' => ['2026-12-31 15:30:00', 'Asia/Jakarta', '2026-01-01..2026-12-31'];
        yield 'Makassar 23.30 masih 2026' => ['2026-12-31 15:30:00', 'Asia/Makassar', '2026-01-01..2026-12-31'];
        yield 'Jayapura 00.30 sudah 2027' => ['2026-12-31 15:30:00', 'Asia/Jayapura', '2027-01-01..2027-12-31'];
    }

    #[DataProvider('sameInstantInDifferentZones')]
    public function test_this_year_follows_the_users_zone_not_the_servers(string $utc, string $zone, string $expected): void
    {
        // Jam dibekukan lalu dibaca dengan zona pengguna, persis seperti `AnalyticsPrincipal::now()`.
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));

        $this->assertSame($expected, RelativeRange::expression('@this_year', CarbonImmutable::now($zone)));
    }

    public function test_today_and_this_month_cross_midnight_per_zone_at_the_end_of_a_month(): void
    {
        // 30 September 16.30 UTC: awal Oktober pukul 00.30 WITA, tetapi masih 23.30 di Jakarta.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 16:30:00', 'UTC'));

        $this->assertSame('2026-09-30..2026-09-30', RelativeRange::expression('@today', CarbonImmutable::now('Asia/Jakarta')));
        $this->assertSame('2026-09-01..2026-09-30', RelativeRange::expression('@this_month', CarbonImmutable::now('Asia/Jakarta')));
        $this->assertSame('2026-10-01..2026-10-01', RelativeRange::expression('@today', CarbonImmutable::now('Asia/Makassar')));
        $this->assertSame('2026-10-01..2026-10-31', RelativeRange::expression('@this_month', CarbonImmutable::now('Asia/Makassar')));
        $this->assertSame('2026-09-01..2026-09-30', RelativeRange::expression('@last_month', CarbonImmutable::now('Asia/Makassar')));
    }

    public function test_bounds_are_whole_days_in_the_users_zone(): void
    {
        [$from, $to] = RelativeRange::bounds('@this_month', CarbonImmutable::parse('2026-10-15 13:45:10', 'Asia/Makassar'));

        $this->assertSame('2026-10-01 00:00:00 Asia/Makassar', $from->format('Y-m-d H:i:s e'));
        $this->assertSame('2026-10-31 00:00:00 Asia/Makassar', $to->format('Y-m-d H:i:s e'));
    }

    public function test_a_date_expression_is_returned_as_written(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Jakarta');

        foreach (['01/01/2026..31/03/2026', '>=01/01/2026', 't', '2026-10-15'] as $expression) {
            $this->assertSame($expression, RelativeRange::expression($expression, $now));
        }
    }

    public function test_only_a_leading_at_sign_makes_a_token(): void
    {
        $this->assertTrue(RelativeRange::isToken('@this_month'));
        $this->assertTrue(RelativeRange::isToken('@bukan_token'));
        $this->assertFalse(RelativeRange::isToken('this_month'));
        $this->assertFalse(RelativeRange::isToken(' @this_month'));

        $this->assertTrue(RelativeRange::known('@this_month'));
        $this->assertFalse(RelativeRange::known('@bukan_token'));
        // Nama token peka huruf besar, seperti tersimpan di widget tenant.
        $this->assertFalse(RelativeRange::known('@This_Month'));
    }

    public function test_an_unknown_token_is_rejected_with_the_path_of_the_range(): void
    {
        foreach (['@next_month', '@This_Month', '@'] as $token) {
            try {
                RelativeRange::expression($token, CarbonImmutable::parse('2026-10-15', 'Asia/Jakarta'));
                $this->fail("Token {$token} seharusnya ditolak.");
            } catch (AnalyticsQueryException $e) {
                $this->assertSame('analytics.invalid_query', $e->errorCode);
                $this->assertSame(422, $e->status);
                $this->assertSame('time_range.range', $e->field);
                $this->assertStringContainsString('"'.$token.'" tidak dikenal', $e->getMessage());
                $this->assertStringContainsString('@this_month', $e->getMessage());
            }
        }
    }
}
