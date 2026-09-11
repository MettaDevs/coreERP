<?php

namespace App\Http\Requests\Organization;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:150'],
            'company_code' => [
                Rule::requiredIf($organization->classification === 'legal_entity'),
                'nullable',
                'string',
                'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,15}$/',
                Rule::unique('legal_entities', 'company_code')
                    ->where('tenant_id', $organization->tenant_id)
                    ->ignore($organization->id, 'organization_id'),
            ],
            'country_code' => [Rule::requiredIf($organization->classification === 'legal_entity'), 'nullable', 'string', 'size:2'],
            'operating_unit_type' => [
                Rule::requiredIf($organization->classification === 'operating_unit'),
                'nullable',
                Rule::in(array_keys(config('coreerp.operating_unit_types', []))),
            ],
        ];
    }

    /** @return array{name:string,company_code:?string,country_code:?string,operating_unit_type:?string} */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'company_code' => strtoupper($this->string('company_code')->toString()) ?: null,
            'country_code' => strtoupper($this->string('country_code')->toString()) ?: null,
            'operating_unit_type' => $this->string('operating_unit_type')->toString() ?: null,
        ];
    }
}
