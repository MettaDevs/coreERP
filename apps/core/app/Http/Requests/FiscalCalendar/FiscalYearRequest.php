<?php

namespace App\Http\Requests\FiscalCalendar;

use Illuminate\Foundation\Http\FormRequest;

class FiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-number-sequences') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'starts_on' => ['required', 'date'],
            // `months` builds evenly spaced monthly periods, which covers the ordinary case. Explicit periods stay
            // available for calendars that do not divide into months, such as 4-4-5.
            'months' => ['required_without:periods', 'integer', 'min:1', 'max:24'],
            'periods' => ['required_without:months', 'array', 'min:1', 'max:24'],
            'periods.*.name' => ['required', 'string', 'max:40'],
            'periods.*.starts_on' => ['required', 'date'],
            'periods.*.ends_on' => ['required', 'date'],
        ];
    }
}
