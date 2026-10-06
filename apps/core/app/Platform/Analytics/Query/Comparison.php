<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Rentang pembanding untuk perbandingan periode (`compare`, area 13; `docs/todo/analitik/mesin-query.md`
 * bagian *Perbandingan periode*), dihitung menurut zona waktu principal dari rentang waktu query.
 *
 * Pergeserannya dalam bulan atau hari:
 *
 * - `previous_year` selalu 12 bulan.
 * - `previous_period` untuk token kalender memakai langkah tokennya ({@see RelativeRange::STEPS}): bulan ini
 *   dibandingkan dengan bulan lalu, kuartal dengan kuartal, awal tahun sampai hari ini dengan tanggal yang sama
 *   tahun lalu, tujuh hari terakhir dengan tujuh hari sebelumnya.
 * - Untuk rentang lain — ekspresi tanggal tertutup, atau tahun fiskal yang sudah dihitung — sebanyak bulan
 *   penuhnya bila rentang itu bulan penuh, selain itu sebanyak harinya.
 *
 * Rentang bulan penuh tetap bulan penuh sesudah digeser, jadi Februari tahun kabisat utuh sampai tanggal 29
 * walau pembandingnya Februari 28 hari, dan sebaliknya. Pergeseran bulan yang jatuh di tanggal yang tidak ada
 * berhenti di akhir bulan (31 Maret mundur sebulan menjadi 29 atau 28 Februari).
 *
 * Perbandingan butuh rentang yang jelas awal dan akhirnya: token, `a..b`, atau satu tanggal. Rentang terbuka
 * (`>=01/01/2026`) atau pilihan (`a|b`) ditolak, karena "periode sebelumnya" tidak punya arti di sana. Tanggal
 * dibaca dengan bentuk yang sama dengan filter tambahan K-30.
 *
 * {@see self::interval()} adalah pergeseran yang sama sebagai interval PostgreSQL. Compiler menambahkannya ke ember
 * waktu query pembanding, sehingga baris periode lalu berlabel periode yang sedang dilihat dan dapat digabung
 * menurut nilai dimensi yang sama.
 */
final readonly class Comparison
{
    private function __construct(
        public CompareMode $mode,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
        private int $months,
        private int $days,
    ) {}

    /** @throws AnalyticsQueryException rentang tanpa awal dan akhir yang jelas */
    public static function of(CompareMode $mode, TimeRange $range, CarbonImmutable $now): self
    {
        [$from, $to] = self::closedBounds($range, $now) ?? throw self::notClosed();

        $whole = $from->day === 1 && $to->isLastOfMonth();
        [$months, $days] = match (true) {
            $mode === CompareMode::PreviousYear => [12, 0],
            $range->bounds === null && isset(RelativeRange::STEPS[$range->range]) => RelativeRange::STEPS[$range->range],
            $whole => [($to->year - $from->year) * 12 + $to->month - $from->month + 1, 0],
            default => [0, (int) $from->diffInDays($to) + 1],
        };

        if ($months > 0) {
            $previousFrom = $from->subMonthsNoOverflow($months);
            $previousTo = $to->subMonthsNoOverflow($months);
            if ($whole) {
                $previousTo = $previousTo->endOfMonth()->startOfDay();
            }
        } else {
            $previousFrom = $from->subDays($days);
            $previousTo = $to->subDays($days);
        }

        return new self($mode, $previousFrom, $previousTo, $months, $days);
    }

    /**
     * Hari pertama dan terakhir rentang waktu yang tertutup, atau null bila rentang itu terbuka atau berupa
     * pilihan. Token tahun fiskal harus sudah dihitung rentangnya.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     *
     * @throws AnalyticsQueryException token yang tidak dikenal, atau tahun fiskal yang belum dihitung
     */
    public static function closedBounds(TimeRange $range, CarbonImmutable $now): ?array
    {
        $bounds = RelativeRange::boundsOf($range, $now);
        if ($bounds !== null) {
            return $bounds;
        }

        $parts = explode('..', $range->range);
        if (count($parts) > 2) {
            return null;
        }
        $from = self::date(trim($parts[0]), $now);
        $to = count($parts) === 2 ? self::date(trim($parts[1]), $now) : $from;

        return $from === null || $to === null || $from->greaterThan($to) ? null : [$from, $to];
    }

    public static function notClosed(): AnalyticsQueryException
    {
        return AnalyticsQueryException::invalidQuery('time_range.range', 'Perbandingan periode butuh rentang yang jelas awal dan akhirnya, misalnya @this_month atau 01/01/2026..31/03/2026.');
    }

    /** Rentang pembanding sebagai ekspresi `Y-m-d..Y-m-d` untuk `FieldFilterExpression`. */
    public function previousRange(): string
    {
        return $this->previousFrom->toDateString().'..'.$this->previousTo->toDateString();
    }

    /** Pergeseran yang sama sebagai interval PostgreSQL, misalnya `12 months` atau `7 days`. */
    public function interval(): string
    {
        return match (true) {
            $this->months > 0 => "{$this->months} months",
            $this->days > 0 => "{$this->days} days",
            default => throw new LogicException('Perbandingan periode tanpa pergeseran.'),
        };
    }

    /** Tanggal bentuk filter tambahan K-30 (`YYYY-MM-DD`, `DD/MM/YYYY`, `DD-MM-YYYY`, `t`), atau null. */
    private static function date(string $text, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($text === 't' || $text === 'T') {
            return $now->startOfDay();
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $match) === 1) {
            [, $year, $month, $day] = $match;
        } elseif (preg_match('/^(\d{1,2})([\/-])(\d{1,2})\2(\d{4})$/', $text, $match) === 1) {
            [, $day, , $month, $year] = $match;
        } else {
            return null;
        }

        if ((int) $year < 1 || ! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return CarbonImmutable::create((int) $year, (int) $month, (int) $day, 0, 0, 0, $now->getTimezone());
    }
}
