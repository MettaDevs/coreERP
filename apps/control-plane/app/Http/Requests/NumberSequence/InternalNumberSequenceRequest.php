<?php

namespace App\Http\Requests\NumberSequence;

use Illuminate\Foundation\Http\FormRequest;

class InternalNumberSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'manual_value' => ['nullable', 'string', 'max:255'],
            // Rejected at the edge so a malformed id fails as a field error rather than inside the issuing transaction.
            'legal_entity_id' => ['nullable', 'string', 'ulid'],
            'org_unit_id' => ['nullable', 'string', 'ulid'],
        ];
    }
}
