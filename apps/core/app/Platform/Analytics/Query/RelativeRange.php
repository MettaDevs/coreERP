<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Token rentang waktu relatif pada `time_range.range` ("bulan ini", "12 bulan terakhir"), diterjemahkan
 * menjadi rentang tanggal `Y-m-d..Y-m-d` yang dibaca `FieldFilterExpression`. Daftar token dan artinya
 * ada di `docs/todo/analitik/mesin-query.md` bagian *Rentang waktu relatif*.
 *
 * **Diterjemahkan menurut zona pengguna.** Pemanggil membawa waktu sekarang yang sudah berzona
 * (`AnalyticsPrincipal::now()`); kelas ini tidak membaca jam sendiri dan tidak menukar zonanya. Pukul
 * 23.30 di Jakarta pada 31 Desember adalah 1 Januari di Makassar, jadi "tahun ini" bagi keduanya berbeda
 * pada saat yang sama. Rentang itu hari penuh di zona yang sama; mengubahnya menjadi batas UTC untuk
 * kolom tanggal-jam adalah tugas `FieldFilterExpression`.
 *
 * Kelas ini sengaja terpisah dari `RelativeDates` milik preset laporan (K-25): token preset tersimpan di
 * preset tenant dan menjadi **satu tanggal**, token analitik menjadi **rentang**. Menggabungkan keduanya
 * mengubah arti token yang sudah tersimpan.
 *
 * **Tahun fiskal** (`@this_fiscal_year`, `@last_fiscal_year`, area 13) bergantung pada kalender fiskal
 * perusahaan, jadi rentangnya tidak dapat dihitung dari tanggal saja. {@see FiscalYearRange} menghitungnya lewat
 * `FiscalCalendarDirectory` sebelum query dikompilasi dan menyimpannya di `TimeRange::$bounds`;
 * {@see self::boundsOf()} dan {@see self::expressionOf()} membaca rentang itu. Token tahun fiskal yang belum
 * dihitung ditolak dengan pesan yang meminta perusahaannya, tidak diam-diam menjadi tahun kalender.
 */
final class RelativeRange
{
    public const PREFIX = '@';

    /**
     * Token yang dikenal. Nama ini tersimpan di widget dan query tersimpan tenant; menggantinya memutus
     * yang sudah ada. Urutannya urutan tampil di pemilih periode di layar.
     */
    public const TOKENS = [
        '@today',
        '@yesterday',
        '@this_week',
        '@last_week',
        '@this_month',
        '@last_month',
        '@this_quarter',
        '@last_quarter',
        '@this_year',
        '@last_year',
        '@this_fiscal_year',
        '@last_fiscal_year',
        '@last_7_days',
        '@last_30_days',
        '@last_90_days',
        '@last_12_months',
        '@year_to_date',
        '@month_to_date',
    ];

    /** Token yang rentangnya bergantung pada kalender fiskal perusahaan, bukan pada tanggal saja. */
    public const FISCAL_TOKENS = ['@this_fiscal_year', '@last_fiscal_year'];

    /**
     * Langkah "periode sebelumnya" tiap token kalender untuk perbandingan periode ({@see Comparison}):
     * `[bulan, hari]`, salah satunya nol. Bulan ini dibandingkan dengan bulan lalu, bukan dengan 31 hari
     * sebelumnya, dan awal bulan sampai hari ini dengan tanggal yang sama bulan lalu.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const STEPS = [
        '@today' => [0, 1],
        '@yesterday' => [0, 1],
        '@this_week' => [0, 7],
        '@last_week' => [0, 7],
        '@this_month' => [1, 0],
        '@last_month' => [1, 0],
        '@this_quarter' => [3, 0],
        '@last_quarter' => [3, 0],
        '@this_year' => [12, 0],
        '@last_year' => [12, 0],
        '@last_7_days' => [0, 7],
        '@last_30_days' => [0, 30],
        '@last_90_days' => [0, 90],
        '@last_12_months' => [12, 0],
        '@year_to_date' => [12, 0],
        '@month_to_date' => [1, 0],
    ];

    /** Nilai `time_range.range` yang berawalan `@` adalah token, bukan ekspresi tanggal. */
    public static function isToken(string $range): bool
    {
        return str_starts_with($range, self::PREFIX);
    }

    public static function known(string $token): bool
    {
        return in_array($token, self::TOKENS, true);
    }

    public static function isFiscal(string $range): bool
    {
        return in_array($range, self::FISCAL_TOKENS, true);
    }

    /**
     * Ekspresi untuk `FieldFilterExpression` dari rentang waktu query: rentang tahun fiskal yang sudah dihitung,
     * token kalender, atau ekspresi tanggal biasa apa adanya.
     *
     * @throws AnalyticsQueryException token yang tidak dikenal, atau token tahun fiskal yang belum dihitung
     */
    public static function expressionOf(TimeRange $range, CarbonImmutable $now): string
    {
        if ($range->bounds !== null) {
            return $range->bounds[0].'..'.$range->bounds[1];
        }

        return self::expression($range->range, $now);
    }

    /**
     * Hari pertama dan terakhir rentang waktu query bila berupa token (termasuk tahun fiskal yang sudah dihitung),
     * atau null untuk ekspresi tanggal biasa.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     *
     * @throws AnalyticsQueryException
     */
    public static function boundsOf(TimeRange $range, CarbonImmutable $now): ?array
    {
        if ($range->bounds !== null) {
            return [
                CarbonImmutable::parse($range->bounds[0], $now->getTimezone())->startOfDay(),
                CarbonImmutable::parse($range->bounds[1], $now->getTimezone())->startOfDay(),
            ];
        }

        return self::isToken($range->range) ? self::bounds($range->range, $now) : null;
    }

    /**
     * Ekspresi untuk `FieldFilterExpression`: token menjadi `Y-m-d..Y-m-d`, ekspresi tanggal biasa
     * dikembalikan apa adanya.
     *
     * @throws AnalyticsQueryException token yang tidak dikenal
     */
    public static function expression(string $range, CarbonImmutable $now): string
    {
        if (! self::isToken($range)) {
            return $range;
        }

        [$from, $to] = self::bounds($range, $now);

        return $from->toDateString().'..'.$to->toDateString();
    }

    /**
     * Hari pertama dan hari terakhir token itu (keduanya termasuk), di zona `$now`. Pengisi celah deret
     * waktu memakainya untuk tahu titik mana saja yang harus ada.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws AnalyticsQueryException token yang tidak dikenal
     */
    public static function bounds(string $token, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();

        return match ($token) {
            '@today' => [$today, $today],
            '@yesterday' => [$today->subDay(), $today->subDay()],
            '@this_week' => self::week($today),
            '@last_week' => self::week($today->subWeek()),
            '@this_month' => self::month($today),
            '@last_month' => self::month($today->startOfMonth()->subMonthNoOverflow()),
            '@this_quarter' => self::quarter($today),
            '@last_quarter' => self::quarter($today->startOfQuarter()->subMonthsNoOverflow(3)),
            '@this_year' => self::year($today),
            '@last_year' => self::year($today->startOfYear()->subYear()),
            // Hari ini ikut dihitung: tujuh hari terakhir adalah enam hari sebelumnya ditambah hari ini.
            '@last_7_days' => [$today->subDays(6), $today],
            '@last_30_days' => [$today->subDays(29), $today],
            '@last_90_days' => [$today->subDays(89), $today],
            // Bulan penuh: dari awal bulan sebelas bulan lalu sampai akhir bulan ini.
            '@last_12_months' => [$today->startOfMonth()->subMonthsNoOverflow(11), $today->endOfMonth()->startOfDay()],
            '@year_to_date' => [$today->startOfYear(), $today],
            '@month_to_date' => [$today->startOfMonth(), $today],
            '@this_fiscal_year', '@last_fiscal_year' => throw self::fiscalUnresolved(),
            default => throw self::unknown($token),
        };
    }

    /** Galat untuk token berawalan `@` yang tidak ada di {@see self::TOKENS}; dipakai juga {@see QueryValidator}. */
    public static function unknown(string $token): AnalyticsQueryException
    {
        return AnalyticsQueryException::invalidQuery('time_range.range', 'Periode "'.$token.'" tidak dikenal. Pilih salah satu dari: '.implode(', ', self::TOKENS).'.');
    }

    /**
     * Galat untuk token tahun fiskal yang rentangnya belum dihitung: tanpa perusahaan, tahun fiskal tidak punya
     * arti, dan menggantinya diam-diam dengan tahun kalender memulangkan angka periode lain.
     */
    public static function fiscalUnresolved(): AnalyticsQueryException
    {
        return AnalyticsQueryException::invalidQuery('time_range.range', 'Periode tahun fiskal butuh satu perusahaan. Saring data menurut satu perusahaan, atau pilih perusahaan di workspace.');
    }

    /**
     * Minggu mulai Senin, ditulis eksplisit supaya tidak bergantung pada locale Carbon.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function week(CarbonImmutable $day): array
    {
        return [
            $day->startOfWeek(CarbonInterface::MONDAY),
            $day->endOfWeek(CarbonInterface::SUNDAY)->startOfDay(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function month(CarbonImmutable $day): array
    {
        return [$day->startOfMonth(), $day->endOfMonth()->startOfDay()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function quarter(CarbonImmutable $day): array
    {
        return [$day->startOfQuarter(), $day->endOfQuarter()->startOfDay()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function year(CarbonImmutable $day): array
    {
        return [$day->startOfYear(), $day->endOfYear()->startOfDay()];
    }
}
