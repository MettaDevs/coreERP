<?php

namespace App\Http\Requests\ReferenceData;

use Illuminate\Foundation\Http\FormRequest;

final class ConvertUnitsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['from_unit_id' => ['required', 'ulid'], 'to_unit_id' => ['required', 'ulid', 'different:from_unit_id'], 'value' => ['required', 'numeric']]; }
}
