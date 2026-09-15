<?php

namespace App\Actions\Calendar;

use App\Models\WorkingTimeCalendar;
use App\Models\WorkingTimeCalendarDay;
use App\Models\WorkingTimeTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ComposeWorkingTimesService
{
    /**
     * Generate atau perbarui hari dan jam kerja kalender berdasarkan pola template.
     *
     * @return int Jumlah hari yang dibuat atau diperbarui
     */
    public function compose(
        WorkingTimeCalendar $calendar,
        WorkingTimeTemplate $template,
        string $fromDate,
        string $toDate
    ): int {
        $start = Carbon::parse($fromDate)->startOfDay();
        $end = Carbon::parse($toDate)->startOfDay();

        if ($start->gt($end)) {
            throw new InvalidArgumentException('Tanggal mulai tidak boleh melebihi tanggal selesai.');
        }

        // Batasi rentang maksimal 3 tahun agar proses tetap terukur
        if ($start->diffInDays($end) > 1100) {
            throw new InvalidArgumentException('Rentang tanggal pembuatan jadwal maksimal 3 tahun.');
        }

        $templateLines = $template->lines()->orderBy('day_of_week')->orderBy('from_time')->get()->groupBy('day_of_week');

        $daysProcessed = 0;

        DB::transaction(function () use ($calendar, $templateLines, $start, $end, &$daysProcessed) {
            $current = $start->copy();

            while ($current->lte($end)) {
                $dateStr = $current->format('Y-m-d');
                // dayOfWeekIso: 1=Senin..7=Minggu -> kurangi 1 menjadi 0=Senin..6=Minggu
                $dayOfWeek = $current->dayOfWeekIso - 1;

                $linesForDay = $templateLines->get($dayOfWeek, collect());
                $totalHours = (float) $linesForDay->sum('hours');
                $hasWorkingHours = $linesForDay->isNotEmpty() && $totalHours > 0;

                $control = $hasWorkingHours ? 'open' : 'closed';
                $closedForPickup = $linesForDay->isNotEmpty() ? (bool) $linesForDay->first()->closed_for_pickup : true;

                /** @var WorkingTimeCalendarDay $day */
                $day = WorkingTimeCalendarDay::updateOrCreate(
                    [
                        'working_time_calendar_id' => $calendar->id,
                        'date' => $dateStr,
                    ],
                    [
                        'tenant_id' => $calendar->tenant_id,
                        'day_of_week' => $dayOfWeek,
                        'control' => $control,
                        'closed_for_pickup' => $closedForPickup,
                        'hours' => $totalHours,
                    ]
                );

                // Hapus line lama dan buat ulang dari template
                $day->lines()->delete();

                foreach ($linesForDay as $tplLine) {
                    $day->lines()->create([
                        'tenant_id' => $calendar->tenant_id,
                        'from_time' => $tplLine->from_time,
                        'to_time' => $tplLine->to_time,
                        'efficiency' => $tplLine->efficiency,
                        'property' => $tplLine->property,
                        'hours' => $tplLine->hours,
                    ]);
                }

                $daysProcessed++;
                $current->addDay();
            }
        });

        return $daysProcessed;
    }
}
