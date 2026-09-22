<?php

namespace App\Http\Requests\Organization;

use App\Models\OperatingUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    /**
     * Nomor unit dirapikan sebelum divalidasi: huruf kecil dan spasi di ujung adalah salah ketik
     * yang wajar, bukan niat. Spasi di tengah tetap ditolak — itu tidak bisa ditebak maksudnya.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('operating_unit_number'))) {
            $this->merge(['operating_unit_number' => strtoupper(trim($this->input('operating_unit_number')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'classification' => ['required', Rule::in(['legal_entity', 'operating_unit'])],
            'name' => ['required', 'string', 'max:150'],
            'company_code' => [
                Rule::requiredIf($this->input('classification') === 'legal_entity'),
                'nullable',
                'string',
                'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,15}$/',
            ],
            'country_code' => [Rule::requiredIf($this->input('classification') === 'legal_entity'), 'nullable', 'string', 'size:2'],
            'operating_unit_type' => [
                Rule::requiredIf($this->input('classification') === 'operating_unit'),
                'nullable',
                Rule::in(array_keys(config('coreerp.operating_unit_types', []))),
            ],
            'operating_unit_number' => [
                'prohibited_if:classification,legal_entity',
                'nullable',
                'string',
                'max:'.OperatingUnit::PANJANG_NOMOR,
                'regex:'.OperatingUnit::FORMAT_NOMOR,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'operating_unit_number.regex' => 'Nomor unit hanya boleh huruf besar, angka, dan tanda hubung, tanpa spasi.',
            'operating_unit_number.prohibited_if' => 'Nomor unit hanya untuk operating unit.',
        ];
    }

    /** @return array{classification:string,name:string,company_code:?string,country_code:?string,operating_unit_type:?string,operating_unit_number:?string} */
    public function payload(): array
    {
        return [
            'classification' => $this->string('classification')->toString(),
            'name' => $this->string('name')->toString(),
            'company_code' => strtoupper($this->string('company_code')->toString()) ?: null,
            'country_code' => $this->string('country_code')->toString() ?: null,
            'operating_unit_type' => $this->string('operating_unit_type')->toString() ?: null,
            'operating_unit_number' => $this->string('operating_unit_number')->toString() ?: null,
        ];
    }
}
