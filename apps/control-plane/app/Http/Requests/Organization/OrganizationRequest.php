<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'classification' => ['required', Rule::in(['legal_entity', 'operating_unit'])],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'company_code' => [Rule::requiredIf($this->input('classification') === 'legal_entity'), 'nullable', 'string', 'max:50'],
            'country_code' => [Rule::requiredIf($this->input('classification') === 'legal_entity'), 'nullable', 'string', 'size:2'],
            'operating_unit_type' => [
                Rule::requiredIf($this->input('classification') === 'operating_unit'),
                'nullable',
                Rule::in(array_keys(config('coreerp.operating_unit_types', []))),
            ],
        ];
    }

    /** @return array{classification:string,code:string,name:string,company_code:?string,country_code:?string,operating_unit_type:?string} */
    public function payload(): array
    {
        return [
            'classification' => $this->string('classification')->toString(),
            'code' => strtoupper($this->string('code')->toString()),
            'name' => $this->string('name')->toString(),
            'company_code' => $this->string('company_code')->toString() ?: null,
            'country_code' => $this->string('country_code')->toString() ?: null,
            'operating_unit_type' => $this->string('operating_unit_type')->toString() ?: null,
        ];
    }
}
