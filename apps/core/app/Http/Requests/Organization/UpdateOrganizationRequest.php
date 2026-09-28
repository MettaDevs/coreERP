<?php

namespace App\Http\Requests\Organization;

use App\Models\OperatingUnit;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    /** Lihat OrganizationRequest::prepareForValidation(). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('operating_unit_number'))) {
            $this->merge(['operating_unit_number' => strtoupper(trim($this->input('operating_unit_number')))]);
        }
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
            'operating_unit_number' => [
                Rule::prohibitedIf($organization->classification === 'legal_entity'),
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
            'operating_unit_number.prohibited' => 'Nomor unit hanya untuk operating unit.',
        ];
    }

    /**
     * `operating_unit_number` hanya ikut bila dikirim.
     *
     * Pemanggil yang tidak mengenal nomor unit — klien API lama, atau form yang hanya mengubah
     * nama — tidak boleh menghapus nomor yang sudah ada. Nomor itu sudah tertanam di tabel
     * penerjemah aplikasi finance; mengosongkannya diam-diam membuat posting berikutnya tertahan.
     *
     * @return array{name:string,company_code:?string,country_code:?string,operating_unit_type:?string,operating_unit_number?:?string}
     */
    public function payload(): array
    {
        $payload = [
            'name' => $this->string('name')->toString(),
            'company_code' => strtoupper($this->string('company_code')->toString()) ?: null,
            'country_code' => strtoupper($this->string('country_code')->toString()) ?: null,
            'operating_unit_type' => $this->string('operating_unit_type')->toString() ?: null,
        ];

        if ($this->has('operating_unit_number')) {
            $payload['operating_unit_number'] = $this->string('operating_unit_number')->toString() ?: null;
        }

        return $payload;
    }
}
