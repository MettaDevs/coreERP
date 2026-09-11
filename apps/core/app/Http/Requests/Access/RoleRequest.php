<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'duty_codes' => ['required', 'array', 'min:1'],
            'duty_codes.*' => ['required', 'string', Rule::exists('security_duties', 'code')],
            // Role turunan: pemegang role ini ikut memperoleh hak seluruh
            // turunannya. Kepemilikan tenant dan larangan lingkaran diperiksa
            // pada action, bukan di sini.
            'child_role_ids' => ['sometimes', 'array'],
            'child_role_ids.*' => ['required', 'string', Rule::exists('roles', 'id')],
        ];
    }

    /** @return array{name:string,duty_codes:list<string>,child_role_ids:list<string>} */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'duty_codes' => array_values($this->collect('duty_codes')
                ->map(fn (mixed $code): string => (string) $code)
                ->all()),
            'child_role_ids' => array_values($this->collect('child_role_ids')
                ->map(fn (mixed $id): string => (string) $id)
                ->all()),
        ];
    }
}
