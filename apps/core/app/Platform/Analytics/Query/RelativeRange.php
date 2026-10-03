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
 * mengubah arti token yang sudah tersimpan. Tahun fiskal menyusul di fase 2, karena butuh legal entity.
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
        '@last_7_days',
        '@last_30_days',
        '@last_90_days',
        '@last_12_months',
        '@year_to_date',
        '@month_to_date',
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
            default => throw self::unknown($token),
        };
    }

    /** Galat untuk token berawalan `@` yang tidak ada di {@see self::TOKENS}; dipakai juga {@see QueryValidator}. */
    public static function unknown(string $token): AnalyticsQueryException
    {
        return AnalyticsQueryException::invalidQuery('time_range.range', 'Periode "'.$token.'" tidak dikenal. Pilih salah satu dari: '.implode(', ', self::TOKENS).'.');
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
