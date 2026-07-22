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
        ];
    }

    /** @return array{name:string,duty_codes:list<string>} */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'duty_codes' => array_values($this->collect('duty_codes')
                ->map(fn (mixed $code): string => (string) $code)
                ->all()),
        ];
    }
}
