<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\CompareMode;
use App\Platform\Analytics\Query\Comparison;
use App\Platform\Analytics\Query\RelativeRange;
use App\Platform\Analytics\Query\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rentang pembanding perbandingan periode (area 13): pergeseran per token, rentang bulan penuh yang tetap bulan
 * penuh di tahun kabisat, batas tahun, ekspresi tanggal tertutup, dan zona waktu principal. Bahwa compiler
 * menggabungkan baris kedua periode dengan benar dibuktikan `FormulaAndComparisonTest`.
 */
class ComparisonTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: CompareMode, 3: string, 4: string}> */
    public static function ranges(): iterable
    {
        $period = CompareMode::PreviousPeriod;
        $year = CompareMode::PreviousYear;

        // "Sekarang" tanggal setempat; rentang yang diharapkan "dari..sampai" dan pergeserannya.
        yield 'bulan ini dengan bulan lalu' => ['2026-10-15', '@this_month', $period, '2026-09-01..2026-09-30', '1 months'];
        yield 'bulan ini dengan tahun lalu' => ['2026-10-15', '@this_month', $year, '2025-10-01..2025-10-31', '12 months'];
        yield 'Januari dengan Desember tahun sebelumnya' => ['2027-01-20', '@this_month', $period, '2026-12-01..2026-12-31', '1 months'];
        yield 'Maret kabisat dengan Februari 29 hari' => ['2028-03-10', '@this_month', $period, '2028-02-01..2028-02-29', '1 months'];
        yield 'Februari setelah kabisat dengan Februari kabisat' => ['2029-02-10', '@this_month', $year, '2028-02-01..2028-02-29', '12 months'];
        yield 'Februari kabisat dengan Februari biasa' => ['2028-02-10', '@this_month', $year, '2027-02-01..2027-02-28', '12 months'];
        yield 'hari kabisat dengan tahun lalu' => ['2028-02-29', '@today', $year, '2027-02-28..2027-02-28', '12 months'];
        yield 'tahun baru dengan malam tahun baru' => ['2027-01-01', '@today', $period, '2026-12-31..2026-12-31', '1 days'];
        yield 'minggu melintasi tahun' => ['2027-01-01', '@this_week', $period, '2026-12-21..2026-12-27', '7 days'];
        yield 'kuartal pertama dengan kuartal keempat' => ['2027-02-15', '@this_quarter', $period, '2026-10-01..2026-12-31', '3 months'];
        yield 'tahun ini dengan tahun lalu' => ['2026-10-15', '@this_year', $period, '2025-01-01..2025-12-31', '12 months'];
        yield 'awal bulan sampai hari ini' => ['2026-10-15', '@month_to_date', $period, '2026-09-01..2026-09-15', '1 months'];
        yield 'awal bulan sampai 31 Maret' => ['2027-03-31', '@month_to_date', $period, '2027-02-01..2027-02-28', '1 months'];
        yield 'awal bulan sampai 30 Maret' => ['2027-03-30', '@month_to_date', $period, '2027-02-01..2027-02-28', '1 months'];
        yield 'awal tahun sampai hari kabisat' => ['2028-02-29', '@year_to_date', $period, '2027-01-01..2027-02-28', '12 months'];
        yield 'awal tahun sampai akhir Februari biasa' => ['2029-02-28', '@year_to_date', $period, '2028-01-01..2028-02-29', '12 months'];
        yield 'tujuh hari terakhir' => ['2027-01-03', '@last_7_days', $period, '2026-12-21..2026-12-27', '7 days'];
        yield 'tiga puluh hari terakhir di akhir bulan' => ['2026-09-30', '@last_30_days', $period, '2026-08-02..2026-08-31', '30 days'];
        yield '12 bulan terakhir' => ['2026-10-15', '@last_12_months', $period, '2024-11-01..2025-10-31', '12 months'];
        yield 'rentang bulan penuh tertulis' => ['2026-10-15', '01/01/2026..31/03/2026', $period, '2025-10-01..2025-12-31', '3 months'];
        yield 'rentang hari tertulis' => ['2026-10-15', '2026-01-10..2026-01-20', $period, '2025-12-30..2026-01-09', '11 days'];
        yield 'satu tanggal tertulis' => ['2026-10-15', '01-03-2028', $year, '2027-03-01..2027-03-01', '12 months'];
        yield 'hari ini tertulis' => ['2026-10-15', 't', $period, '2026-10-14..2026-10-14', '1 days'];
    }

    #[DataProvider('ranges')]
    public function test_the_previous_range_and_its_shift(string $today, string $range, CompareMode $mode, string $previous, string $interval): void
    {
        $comparison = Comparison::of($mode, new TimeRange($range), CarbonImmutable::parse($today.' 10:00', 'Asia/Makassar'));

        $this->assertSame($previous, $comparison->previousRange());
        $this->assertSame($interval, $comparison->interval());
    }

    public function test_the_range_follows_the_principal_time_zone(): void
    {
        // 31 Desember 23.30 di Jakarta adalah 1 Januari di Makassar: "bulan ini" dan pembandingnya berbeda.
        $moment = CarbonImmutable::parse('2026-12-31 16:30', 'UTC');

        $this->assertSame('2026-11-01..2026-11-30', Comparison::of(CompareMode::PreviousPeriod, new TimeRange('@this_month'), $moment->setTimezone('Asia/Jakarta'))->previousRange());
        $this->assertSame('2026-12-01..2026-12-31', Comparison::of(CompareMode::PreviousPeriod, new TimeRange('@this_month'), $moment->setTimezone('Asia/Makassar'))->previousRange());
    }

    public function test_a_resolved_fiscal_year_is_shifted_by_its_whole_months(): void
    {
        $range = new TimeRange('@this_fiscal_year', null, ['2026-07-01', '2027-06-30']);
        $now = CarbonImmutable::parse('2026-10-15', 'Asia/Jakarta');

        $this->assertSame('2025-07-01..2026-06-30', Comparison::of(CompareMode::PreviousPeriod, $range, $now)->previousRange());
        $this->assertSame('2026-07-01..2027-06-30', RelativeRange::expressionOf($range, $now));
    }

    public function test_open_or_multiple_ranges_cannot_be_compared(): void
    {
        $now = CarbonImmutable::parse('2026-10-15', 'Asia/Jakarta');

        foreach (['>=01/01/2026', '01/01/2026..', '..31/03/2026', '01/01/2026|01/02/2026', '31/03/2026..01/01/2026', '30/02/2026', 'kemarin', '01/01/2026..02/01/2026..03/01/2026'] as $range) {
            $this->assertNull(Comparison::closedBounds(new TimeRange($range), $now), "`{$range}` tidak punya awal dan akhir yang jelas.");
            try {
                Comparison::of(CompareMode::PreviousPeriod, new TimeRange($range), $now);
                $this->fail("`{$range}` seharusnya ditolak.");
            } catch (AnalyticsQueryException $e) {
                $this->assertSame('time_range.range', $e->field);
            }
        }
    }

    public function test_an_unresolved_fiscal_year_asks_for_a_company_instead_of_becoming_a_calendar_year(): void
    {
        $now = CarbonImmutable::parse('2026-10-15', 'Asia/Jakarta');

        foreach (RelativeRange::FISCAL_TOKENS as $token) {
            $this->assertTrue(RelativeRange::known($token));
            try {
                RelativeRange::expressionOf(new TimeRange($token), $now);
                $this->fail("{$token} tanpa rentang seharusnya ditolak.");
            } catch (AnalyticsQueryException $e) {
                $this->assertSame('time_range.range', $e->field);
                $this->assertStringContainsString('butuh satu perusahaan', $e->getMessage());
            }
        }
    }
}
