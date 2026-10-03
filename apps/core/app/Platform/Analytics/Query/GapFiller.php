<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Mengisi celah deret waktu: periode tanpa baris tetap muncul, dengan nol untuk `count` dan `sum`, dan
 * kosong untuk `avg`, `min`, `max` — rata-rata dari nol baris bukan nol. Diisi di PHP sesudah query,
 * untuk setiap kombinasi dimensi lain (termasuk mata uang tersirat) yang muncul di hasil.
 *
 * Rentangnya rentang waktu query bila berupa token (`@this_year`) pada field yang sama dengan ember
 * waktunya — sehingga bulan kosong di ujung rentang ikut muncul — dan selain itu dari periode pertama
 * sampai terakhir yang ada di hasil. Ember waktu yang pertama yang diisi; dimensi waktu lain diperlakukan
 * seperti dimensi biasa.
 *
 * Celah **tidak** diisi bila mengisinya akan berbohong atau membengkak:
 *
 * - hasil terpotong — periode yang hilang mungkin justru terpotong, bukan kosong (pemanggil memeriksanya);
 * - urutan pertama bukan periode itu — pengguna meminta urutan lain, misalnya nilai terbesar, dan baris
 *   isian akan merusaknya;
 * - lebih dari {@see self::MAX_POINTS} periode, atau baris sesudah diisi melebihi batas baris query.
 *
 * Baris disusun per periode, naik (atau turun bila periode diurutkan turun). Di dalam satu periode, baris
 * yang ada tetap dalam urutan SQL-nya, lalu baris isian menyusul dalam urutan kemunculan pertama
 * kombinasinya — tepat untuk urutan turun menurut measure (nol dan kosong memang di akhir), dan hanya
 * mendekati untuk urutan naik menurut dimensi lain. Periode kosong (kolom waktu tanpa nilai) tetap di akhir.
 */
final class GapFiller
{
    public const MAX_POINTS = 1000;

    /**
     * @param  list<ResultColumn>  $columns
     * @param  list<array<string, scalar|null>>  $rows  baris berkunci dataset, sudah berlabel, tidak terpotong
     * @return list<array<string, scalar|null>>
     */
    public static function fill(CompiledDataset $dataset, AnalyticsQuery $query, array $columns, array $rows, CarbonImmutable $now, int $limit): array
    {
        $period = null;
        foreach ($columns as $column) {
            if ($column->granularity !== null) {
                $period = $column;
                break;
            }
        }
        if (! $query->fillGaps || $period === null) {
            return $rows;
        }

        $first = $query->sort[0] ?? null;
        if ($first !== null && $first['key'] !== $period->key) {
            return $rows;
        }

        $granularity = TimeGranularity::from((string) $period->granularity);
        $observed = [];
        foreach ($rows as $row) {
            if (is_string($row[$period->key] ?? null)) {
                $observed[] = $row[$period->key];
            }
        }
        $bounds = $observed === [] ? [] : [min($observed), max($observed)];

        $range = $query->timeRange;
        if ($range !== null && RelativeRange::isToken($range->range) && ($range->field ?? $dataset->defaultTime()) === $period->key) {
            [$from, $to] = RelativeRange::bounds($range->range, $now);
            $from = self::bucket(CarbonImmutable::parse($from->toDateString(), 'UTC'), $granularity)->toDateString();
            $to = self::bucket(CarbonImmutable::parse($to->toDateString(), 'UTC'), $granularity)->toDateString();
            $bounds = $bounds === [] ? [$from, $to] : [min($from, $bounds[0]), max($to, $bounds[1])];
        }
        if ($bounds === []) {
            return $rows;
        }

        $periods = [];
        for ($at = self::bucket(CarbonImmutable::parse($bounds[0], 'UTC'), $granularity); $at->toDateString() <= $bounds[1]; $at = self::next($at, $granularity)) {
            if (count($periods) === self::MAX_POINTS) {
                return $rows;
            }
            $periods[] = $at->toDateString();
        }
        if ($first !== null && $first['direction'] === 'desc') {
            $periods = array_reverse($periods);
        }

        // Kombinasi dimensi lain, dalam urutan kemunculan pertamanya; baris berperiode kosong disisihkan.
        $others = array_values(array_filter($columns, static fn (ResultColumn $column): bool => $column->kind === ResultColumn::DIMENSION && $column !== $period));
        $combos = [];
        $byPeriod = [];
        $present = [];
        $undated = [];
        foreach ($rows as $row) {
            $combo = json_encode(array_map(static fn (ResultColumn $column): mixed => $row[$column->key] ?? null, $others), JSON_THROW_ON_ERROR);
            $combos[$combo] ??= $row;
            $date = $row[$period->key] ?? null;
            if (is_string($date)) {
                $byPeriod[$date][] = $row;
                $present[$date][$combo] = true;
            } else {
                $undated[] = $row;
            }
        }
        // Tanpa baris sama sekali, satu-satunya kombinasi yang pasti ada adalah "tanpa dimensi lain".
        if ($combos === [] && $others === []) {
            $combos['[]'] = [];
        }

        if (count($periods) * count($combos) + count($undated) > $limit) {
            return $rows;
        }

        $filled = [];
        foreach ($periods as $date) {
            // Baris yang ada tetap dalam urutan SQL-nya; isian menyusul di akhir periodenya.
            array_push($filled, ...($byPeriod[$date] ?? []));
            foreach ($combos as $combo => $sample) {
                if (! isset($present[$date][$combo])) {
                    $filled[] = self::empty($columns, $period, $sample, $date);
                }
            }
        }

        return [...$filled, ...$undated];
    }

    /**
     * Baris isian satu periode untuk satu kombinasi: nilai dan label dimensi lain dari baris contohnya,
     * nol untuk jumlah dan hitungan, kosong untuk yang lain.
     *
     * @param  list<ResultColumn>  $columns
     * @param  array<string, scalar|null>  $sample
     * @return array<string, scalar|null>
     */
    private static function empty(array $columns, ResultColumn $period, array $sample, string $date): array
    {
        $row = [];
        foreach ($columns as $column) {
            if ($column === $period) {
                $row[$column->key] = $date;
            } elseif ($column->kind === ResultColumn::DIMENSION) {
                $row[$column->key] = $sample[$column->key] ?? null;
                if ($column->labelKey !== null) {
                    $row[$column->labelKey] = $sample[$column->labelKey] ?? null;
                }
            } else {
                $row[$column->key] = match ($column->aggregate) {
                    Aggregate::Count, Aggregate::CountDistinct => 0,
                    // Sama dengan `coalesce(sum(…), 0)` untuk kelompok tanpa baris.
                    Aggregate::Sum => '0',
                    default => null,
                };
            }
        }

        return $row;
    }

    /** Awal ember tanggal ini; minggu mulai Senin, sama dengan `date_trunc('week', …)`. */
    private static function bucket(CarbonImmutable $day, TimeGranularity $granularity): CarbonImmutable
    {
        return match ($granularity) {
            TimeGranularity::Day => $day->startOfDay(),
            TimeGranularity::Week => $day->startOfWeek(CarbonInterface::MONDAY),
            TimeGranularity::Month => $day->startOfMonth(),
            TimeGranularity::Quarter => $day->startOfQuarter(),
            TimeGranularity::Year => $day->startOfYear(),
        };
    }

    private static function next(CarbonImmutable $start, TimeGranularity $granularity): CarbonImmutable
    {
        return match ($granularity) {
            TimeGranularity::Day => $start->addDay(),
            TimeGranularity::Week => $start->addWeek(),
            TimeGranularity::Month => $start->addMonthNoOverflow(),
            TimeGranularity::Quarter => $start->addMonthsNoOverflow(3),
            TimeGranularity::Year => $start->addYear(),
        };
    }
}
