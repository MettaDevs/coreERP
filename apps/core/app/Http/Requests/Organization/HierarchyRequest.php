<?php

namespace App\Http\Requests\Organization;

use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HierarchyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentWorkspace::class)->membership($this)?->tenant_id;

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('organization_hierarchies', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'purpose_codes' => ['required', 'array', 'min:1'],
            'purpose_codes.*' => ['required', 'string', 'exists:hierarchy_purposes,code'],
            'root_organization_id' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Nama susunan organisasi sudah dipakai. Pilih nama lain.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /** @return array{name:string,purpose_codes:list<string>,root_organization_id:string,effective_from:string} */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'purpose_codes' => array_values($this->collect('purpose_codes')->map(fn (mixed $code): string => (string) $code)->all()),
            'root_organization_id' => $this->string('root_organization_id')->toString(),
            'effective_from' => $this->string('effective_from')->toString(),
        ];
    }
}
