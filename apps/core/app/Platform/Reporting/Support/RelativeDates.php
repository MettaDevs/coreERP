<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Support;

use Carbon\CarbonImmutable;

/**
 * Tanggal relatif pada preset laporan (K-25): "bulan ini", "bulan lalu", "tahun ini", dan sejenisnya,
 * disimpan sebagai token dan diterjemahkan menjadi tanggal saat preset dipakai.
 *
 * Business Central menulisnya sebagai rumus tanggal (`CM`, `-1M`) di kolom filter. Pengguna di sini tidak
 * mengetik rumus: token dipilih dari daftar di layar simpan preset, jadi yang perlu stabil hanya namanya.
 *
 * **Diterjemahkan menurut zona pengguna.** "Bulan ini" bagi orang di Jakarta pada 1 Oktober pukul 00.30 WIB
 * adalah Oktober, walaupun jam server (UTC) masih menunjukkan 30 September. Pemanggil membawa waktu
 * sekarang yang sudah berzona; kelas ini tidak membaca jam sendiri.
 *
 * Tokennya selalu satu nilai utuh. `@this_month.start` dan `@this_month.end` menjadi tanggal (`Y-m-d`)
 * untuk parameter rentang, `@this_month` dan `@last_month` menjadi bulan (`Y-m`) untuk parameter periode.
 * Nilai lain dibiarkan apa adanya.
 */
final class RelativeDates
{
    public const PREFIX = '@';

    /** Token yang dikenal. Nama ini tersimpan di preset tenant; menggantinya memutus preset yang sudah ada. */
    public const TOKENS = [
        '@today',
        '@this_month.start', '@this_month.end',
        '@last_month.start', '@last_month.end',
        '@this_year.start', '@this_year.end',
        '@last_year.start', '@last_year.end',
        '@this_month', '@last_month',
    ];

    public static function isToken(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public static function known(string $token): bool
    {
        return in_array($token, self::TOKENS, true);
    }

    /**
     * Semua token pada parameter diganti tanggalnya. Nilai daftar (filter pilihan banyak) tidak pernah berisi
     * tanggal, jadi hanya nilai tunggal yang diperiksa.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function resolve(array $parameters, CarbonImmutable $now): array
    {
        foreach ($parameters as $key => $value) {
            if (self::isToken($value) && self::known($value)) {
                $parameters[$key] = self::value($value, $now);
            }
        }

        return $parameters;
    }

    private static function value(string $token, CarbonImmutable $now): string
    {
        $today = $now->startOfDay();

        return match ($token) {
            '@today' => $today->toDateString(),
            '@this_month.start' => $today->startOfMonth()->toDateString(),
            '@this_month.end' => $today->endOfMonth()->toDateString(),
            '@last_month.start' => $today->startOfMonth()->subMonthNoOverflow()->toDateString(),
            '@last_month.end' => $today->startOfMonth()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            '@this_year.start' => $today->startOfYear()->toDateString(),
            '@this_year.end' => $today->endOfYear()->toDateString(),
            '@last_year.start' => $today->startOfYear()->subYear()->toDateString(),
            '@last_year.end' => $today->startOfYear()->subYear()->endOfYear()->toDateString(),
            '@this_month' => $today->format('Y-m'),
            '@last_month' => $today->startOfMonth()->subMonthNoOverflow()->format('Y-m'),
            default => $token,
        };
    }
}
