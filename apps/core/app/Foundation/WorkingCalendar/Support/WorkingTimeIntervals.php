<?php

namespace App\Foundation\WorkingCalendar\Support;

use Illuminate\Validation\ValidationException;

/** Aturan interval satu hari dipakai pola mingguan dan kalender bertanggal. */
final class WorkingTimeIntervals
{
    public const TIME_PATTERN = '/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/';

    /** @param array<int, array<string, mixed>> $lines
     * @return array<int, array{from_time: ?string, to_time: ?string, efficiency: float, property: ?string, hours: float}>
     */
    public static function normalize(array $lines): array
    {
        $errors = [];
        $normalized = [];
        $ranges = [];

        foreach ($lines as $index => $line) {
            $from = $line['from_time'] ?? null;
            $to = $line['to_time'] ?? null;

            if (($from !== null && ! preg_match(self::TIME_PATTERN, $from)) || ($to !== null && ! preg_match(self::TIME_PATTERN, $to))) {
                $errors["lines.{$index}.from_time"] = 'Jam ditulis JJ:MM, dari 00:00 sampai 24:00.';

                continue;
            }
            if (($from === null) !== ($to === null)) {
                $errors["lines.{$index}.to_time"] = 'Isi jam mulai dan jam selesai, atau kosongkan keduanya.';

                continue;
            }

            $hours = (float) ($line['hours'] ?? 0);

            if ($from !== null && $to !== null) {
                $start = self::minutes($from);
                $end = self::minutes($to);

                if ($end <= $start) {
                    $errors["lines.{$index}.to_time"] = 'Jam selesai harus setelah jam mulai.';

                    continue;
                }

                $hours = round(($end - $start) / 60, 2);
                $ranges[] = ['index' => $index, 'start' => $start, 'end' => $end];
            }

            $normalized[$index] = [
                'from_time' => $from,
                'to_time' => $to,
                'efficiency' => (float) ($line['efficiency'] ?? 100),
                'property' => $line['property'] ?? null,
                'hours' => $hours,
            ];
        }

        usort($ranges, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i]['start'] < $ranges[$i - 1]['end']) {
                $errors["lines.{$ranges[$i]['index']}.from_time"] = 'Rentang jam ini bertumpuk dengan baris lain.';
            }
        }

        if ($errors === [] && array_sum(array_column($normalized, 'hours')) > 24) {
            $errors['lines'] = 'Jumlah jam dalam satu hari tidak boleh lebih dari 24.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
