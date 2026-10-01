<?php

namespace Modules\Apperp\ManagementAset\Support;

use Illuminate\Support\Carbon;

/**
 * Dasar hitung baris rencana pemeliharaan; padanan *Interval type* pada baris rencana F&O.
 *
 * Tiga dari sekian pilihan F&O yang dipakai di sini, dan ketiganya cukup untuk kalibrasi berkala dan
 * servis berbasis pemakaian:
 *
 * - `tanggal_mulai` — *Repeated from start date*: jatuh tempo pada tanggal mulai, lalu setiap interval
 *   sesudahnya, tidak peduli kapan pekerjaan terakhir selesai.
 * - `work_order_terakhir` — *Repeated from last work order*: interval dihitung dari tanggal selesai
 *   aktual work order terakhir untuk aset dan jenis pekerjaan yang sama; tanpa work order, jatuh
 *   tempo pertama adalah tanggal mulai.
 * - `nilai_counter` — *Repeated on aggregated value*: jatuh tempo pada setiap kelipatan interval
 *   counter dari total counter aset.
 *
 * Pilihan F&O lain (*once*, *linked*, *once reached above/below*, musim) belum ada.
 */
final class MaintenancePlanBasis
{
    public const START_DATE = 'tanggal_mulai';

    public const LAST_WORK_ORDER = 'work_order_terakhir';

    public const COUNTER = 'nilai_counter';

    public const ALL = [self::START_DATE, self::LAST_WORK_ORDER, self::COUNTER];

    public const TIME_BASED = [self::START_DATE, self::LAST_WORK_ORDER];

    public const LABELS = [
        self::START_DATE => 'Berulang dari tanggal mulai',
        self::LAST_WORK_ORDER => 'Berulang dari work order terakhir',
        self::COUNTER => 'Berulang setiap nilai counter',
    ];

    public const UNITS = ['hari', 'minggu', 'bulan', 'tahun'];

    public static function isTimeBased(string $basis): bool
    {
        return in_array($basis, self::TIME_BASED, true);
    }

    /**
     * Tanggal sesudah `$times` kali interval. Bulan dan tahun tidak melimpah: 31 Januari ditambah satu
     * bulan menjadi 28 atau 29 Februari, bukan awal Maret.
     */
    public static function add(Carbon $date, int $interval, string $unit, int $times = 1): Carbon
    {
        $steps = $interval * $times;

        return match ($unit) {
            'hari' => $date->copy()->addDays($steps),
            'minggu' => $date->copy()->addWeeks($steps),
            'bulan' => $date->copy()->addMonthsNoOverflow($steps),
            'tahun' => $date->copy()->addYearsNoOverflow($steps),
            default => throw new \InvalidArgumentException('Satuan interval tidak dikenal: '.$unit),
        };
    }
}
