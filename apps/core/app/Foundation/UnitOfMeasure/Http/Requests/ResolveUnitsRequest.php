<?php

namespace App\Foundation\UnitOfMeasure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['unit_ids' => ['required', 'array', 'min:1', 'max:100'], 'unit_ids.*' => ['required', 'ulid', 'distinct']];
    }
}
