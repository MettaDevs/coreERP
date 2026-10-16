<?php

namespace Tests\Unit\Foundation\WorkingCalendar;

use App\Foundation\WorkingCalendar\Support\WorkingTimeIntervals;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkingTimeIntervalsTest extends TestCase
{
    public function test_zero_efficiency_and_adjacent_intervals_are_preserved(): void
    {
        $lines = WorkingTimeIntervals::normalize([
            3 => ['from_time' => '08:00', 'to_time' => '12:00', 'efficiency' => 0, 'hours' => 99],
            8 => ['from_time' => '12:00', 'to_time' => '24:00'],
        ]);

        $this->assertSame(0.0, $lines[3]['efficiency']);
        $this->assertSame(4.0, $lines[3]['hours']);
        $this->assertSame(12.0, $lines[8]['hours']);
    }

    public function test_invalid_intervals_are_rejected_with_the_original_field_index(): void
    {
        $cases = [
            [['from_time' => '25:00', 'to_time' => '26:00']],
            [['from_time' => '08:70', 'to_time' => '12:00']],
            [['from_time' => 'garbage', 'to_time' => '12:00']],
            [['from_time' => '08:00', 'to_time' => null]],
            [['from_time' => '12:00', 'to_time' => '08:00']],
            [['from_time' => '08:00', 'to_time' => '08:00']],
            [['from_time' => '08:00', 'to_time' => '12:00'], ['from_time' => '11:00', 'to_time' => '13:00']],
            [['from_time' => '00:00', 'to_time' => '24:00'], ['hours' => 1]],
        ];
        foreach ($cases as $case) {
            try {
                WorkingTimeIntervals::normalize(array_combine(range(10, 9 + count($case)), $case));
                $this->fail('Invalid intervals must not be accepted.');
            } catch (ValidationException $error) {
                foreach (array_keys($error->errors()) as $field) {
                    $this->assertMatchesRegularExpression('/^lines(?:\.1[01]\.(?:from_time|to_time))?$/', $field);
                }
            }
        }
    }
}
