<?php

namespace App\Http\Requests\Onboarding;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BusinessRegistrationRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'module_ids' => ['required', 'array', 'min:1'],
            'module_ids.*' => ['required', 'string', 'distinct', Rule::exists('modules', 'id')->where('status', 'available')],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ];
    }

    /** @return array{name:string,business_name:string,email:string,password:string,module_ids:list<string>} */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'business_name' => $this->string('business_name')->toString(),
            'module_ids' => array_values($this->collect('module_ids')->map(fn (mixed $id): string => (string) $id)->all()),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}
