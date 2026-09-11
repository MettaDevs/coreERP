<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class WorkspaceContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'string'],
            'legal_entity_id' => ['nullable', 'string'],
            'org_unit_id' => ['nullable', 'string'],
        ];
    }
}
