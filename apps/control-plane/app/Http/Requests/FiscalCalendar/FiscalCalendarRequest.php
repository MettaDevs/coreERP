<?php

namespace App\Http\Requests\FiscalCalendar;

use Illuminate\Foundation\Http\FormRequest;

class FiscalCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A fiscal calendar decides which period a document number belongs to, so it is governed by the same
        // responsibility as the number sequences that consume it.
        return $this->user()?->can('manage-number-sequences') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:150'],
        ];
    }
}
