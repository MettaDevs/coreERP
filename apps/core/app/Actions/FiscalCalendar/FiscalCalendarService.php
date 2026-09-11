<?php

namespace App\Actions\FiscalCalendar;

use App\Models\FiscalCalendar;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\LegalEntity;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fiscal calendars follow the Dynamics 365 shape: a calendar is shared inside a tenant, a legal entity points at one
 * calendar, and each fiscal year owns an ordered set of periods. Legal entity owns the fiscal-calendar consequence, so
 * nothing resolves a fiscal period without a legal entity in context.
 *
 * Non-overlap is enforced here rather than in the schema because the invariant needs a range comparison that does not
 * translate to a portable constraint. Every write path must go through this service.
 */
class FiscalCalendarService
{
    /**
     * @param  list<array{name:string,starts_on:string|CarbonInterface,ends_on:string|CarbonInterface}>  $periods
     */
    public function defineYear(FiscalCalendar $calendar, string $name, string|CarbonInterface $startsOn, string|CarbonInterface $endsOn, array $periods): FiscalYear
    {
        $start = Carbon::parse($startsOn)->startOfDay();
        $end = Carbon::parse($endsOn)->startOfDay();
        if (! $end->greaterThan($start)) {
            $this->fail('ends_on', 'Tahun fiskal harus berakhir setelah tanggal mulai.');
        }
        if ($periods === []) {
            $this->fail('periods', 'Tahun fiskal harus memiliki minimal satu periode.');
        }

        return DB::transaction(function () use ($calendar, $name, $start, $end, $periods): FiscalYear {
            $overlapping = FiscalYear::query()
                ->where('fiscal_calendar_id', $calendar->id)
                ->where('starts_on', '<=', $end)
                ->where('ends_on', '>=', $start)
                ->lockForUpdate()
                ->exists();
            if ($overlapping) {
                $this->fail('starts_on', 'Rentang tahun fiskal bertumpang tindih dengan tahun fiskal lain pada kalender ini.');
            }

            $year = FiscalYear::query()->create([
                'fiscal_calendar_id' => $calendar->id,
                'name' => $name,
                'starts_on' => $start,
                'ends_on' => $end,
            ]);

            $ordinal = 0;
            $previousEnd = null;
            foreach ($this->sortedPeriods($periods) as $period) {
                $periodStart = Carbon::parse($period['starts_on'])->startOfDay();
                $periodEnd = Carbon::parse($period['ends_on'])->startOfDay();
                if (! $periodEnd->greaterThanOrEqualTo($periodStart)) {
                    $this->fail('periods', 'Periode fiskal harus berakhir pada atau setelah tanggal mulai.');
                }
                if ($periodStart->lessThan($start) || $periodEnd->greaterThan($end)) {
                    $this->fail('periods', 'Periode fiskal harus berada di dalam rentang tahun fiskalnya.');
                }
                if ($previousEnd !== null && ! $periodStart->equalTo($previousEnd->copy()->addDay())) {
                    $this->fail('periods', 'Periode fiskal harus berurutan tanpa celah atau tumpang tindih.');
                }

                FiscalPeriod::query()->create([
                    'fiscal_year_id' => $year->id,
                    'ordinal' => ++$ordinal,
                    'name' => $period['name'],
                    'starts_on' => $periodStart,
                    'ends_on' => $periodEnd,
                ]);
                $previousEnd = $periodEnd;
            }

            if ($previousEnd === null || ! $previousEnd->equalTo($end)) {
                $this->fail('periods', 'Periode fiskal harus menutup seluruh rentang tahun fiskal.');
            }

            return $year->refresh();
        });
    }

    /**
     * Build twelve calendar-month periods for a fiscal year that starts on the first day of a month.
     *
     * @return list<array{name:string,starts_on:CarbonInterface,ends_on:CarbonInterface}>
     */
    public function monthlyPeriods(string|CarbonInterface $startsOn, int $months = 12): array
    {
        $cursor = Carbon::parse($startsOn)->startOfDay();
        $periods = [];
        for ($index = 0; $index < $months; $index++) {
            $periodStart = $cursor->copy();
            $periodEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $periods[] = [
                'name' => $periodStart->format('M Y'),
                'starts_on' => $periodStart,
                'ends_on' => $periodEnd,
            ];
            $cursor = $periodEnd->copy()->addDay();
        }

        return $periods;
    }

    /**
     * Resolve the fiscal position of a legal entity on a date.
     *
     * @return array{year_id:string,year_name:string,period_id:string,period_ordinal:int,period_name:string}
     */
    public function resolve(LegalEntity $legalEntity, CarbonInterface $date): array
    {
        if ($legalEntity->fiscal_calendar_id === null) {
            $this->fail('scope', 'Entitas legal ini belum memiliki kalender fiskal.');
        }

        $day = Carbon::parse($date)->startOfDay();
        $year = FiscalYear::query()
            ->where('fiscal_calendar_id', $legalEntity->fiscal_calendar_id)
            ->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day)
            ->first();
        if (! $year) {
            $this->fail('scope', 'Tanggal ini berada di luar tahun fiskal yang sudah didefinisikan.');
        }

        $period = FiscalPeriod::query()
            ->where('fiscal_year_id', $year->id)
            ->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day)
            ->first();
        if (! $period) {
            $this->fail('scope', 'Tanggal ini berada di luar periode fiskal yang sudah didefinisikan.');
        }

        return [
            'year_id' => $year->id,
            'year_name' => $year->name,
            'period_id' => $period->id,
            'period_ordinal' => (int) $period->ordinal,
            'period_name' => $period->name,
        ];
    }

    /**
     * @param  list<array{name:string,starts_on:string|CarbonInterface,ends_on:string|CarbonInterface}>  $periods
     * @return list<array{name:string,starts_on:string|CarbonInterface,ends_on:string|CarbonInterface}>
     */
    private function sortedPeriods(array $periods): array
    {
        usort($periods, fn (array $left, array $right): int => Carbon::parse($left['starts_on']) <=> Carbon::parse($right['starts_on']));

        return array_values($periods);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
