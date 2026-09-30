<?php

namespace App\Foundation\WorkingCalendar\Actions;

use App\Foundation\WorkingCalendar\Models\WorkingTimeCalendar;
use App\Foundation\WorkingCalendar\Models\WorkingTimeCalendarDay;
use App\Foundation\WorkingCalendar\Models\WorkingTimeCalendarLine;
use App\Foundation\WorkingCalendar\Models\WorkingTimeTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ComposeWorkingTimesService
{
    /** Rentang terpanjang satu kali penyusunan, dalam selisih hari (sekitar tiga tahun). */
    public const MAX_DAYS = 1100;

    /** Baris per perintah insert; jauh di bawah batas 65.535 parameter PostgreSQL. */
    private const CHUNK = 500;

    /**
     * Susun hari dan jam kerja kalender untuk satu rentang tanggal dari pola mingguan template.
     *
     * Hari yang sudah ada pada rentang itu ditimpa beserta jam kerjanya, seperti "Compose
     * working times" di Dynamics 365. Semua hari ditulis dengan beberapa perintah massal, bukan
     * satu perintah per hari: rentang tiga tahun sebelumnya memakan ribuan query dalam satu
     * permintaan.
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

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw new InvalidArgumentException('Rentang tanggal pembuatan jadwal maksimal 3 tahun.');
        }

        $templateLines = $template->lines()->orderBy('day_of_week')->orderBy('from_time')->get()->groupBy('day_of_week');
        $now = now();
        $days = [];
        $linesByDate = [];

        for ($current = $start->copy(); $current->lte($end); $current->addDay()) {
            $date = $current->format('Y-m-d');
            // dayOfWeekIso: 1=Senin..7=Minggu -> kurangi 1 menjadi 0=Senin..6=Minggu
            $dayOfWeek = $current->dayOfWeekIso - 1;
            $linesForDay = $templateLines->get($dayOfWeek, collect());
            $totalHours = (float) $linesForDay->sum('hours');

            $days[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $calendar->tenant_id,
                'working_time_calendar_id' => $calendar->id,
                'date' => $date,
                'day_of_week' => $dayOfWeek,
                'control' => $linesForDay->isNotEmpty() && $totalHours > 0 ? 'open' : 'closed',
                'closed_for_pickup' => $linesForDay->isNotEmpty() ? (bool) $linesForDay->first()->closed_for_pickup : true,
                'hours' => $totalHours,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $linesByDate[$date] = $linesForDay;
        }

        DB::transaction(function () use ($calendar, $days, $linesByDate, $start, $end, $now): void {
            $range = fn () => WorkingTimeCalendarDay::query()
                ->where('working_time_calendar_id', $calendar->id)
                ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')]);

            // Jam kerja lama pada rentang ini diganti seluruhnya oleh pola yang baru.
            WorkingTimeCalendarLine::query()
                ->whereIn('working_time_calendar_day_id', $range()->select('id'))
                ->delete();

            // Hari yang sudah ada mempertahankan id-nya; hanya isinya yang diperbarui.
            foreach (array_chunk($days, self::CHUNK) as $chunk) {
                WorkingTimeCalendarDay::query()->upsert(
                    $chunk,
                    ['working_time_calendar_id', 'date'],
                    ['tenant_id', 'day_of_week', 'control', 'closed_for_pickup', 'hours', 'updated_at'],
                );
            }

            $dayIds = $range()->toBase()->pluck('id', 'date');
            $lines = [];

            foreach ($linesByDate as $date => $templateLinesOfDay) {
                foreach ($templateLinesOfDay as $templateLine) {
                    $lines[] = [
                        'id' => (string) Str::ulid(),
                        'tenant_id' => $calendar->tenant_id,
                        'working_time_calendar_day_id' => $dayIds[$date],
                        'from_time' => $templateLine->from_time,
                        'to_time' => $templateLine->to_time,
                        'efficiency' => $templateLine->efficiency,
                        'property' => $templateLine->property,
                        'hours' => $templateLine->hours,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($lines, self::CHUNK) as $chunk) {
                WorkingTimeCalendarLine::query()->insert($chunk);
            }
        });

        return count($days);
    }
}
