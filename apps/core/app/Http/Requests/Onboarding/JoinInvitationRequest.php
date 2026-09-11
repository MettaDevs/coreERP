<?php

namespace App\Http\Requests\Onboarding;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class JoinInvitationRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ];
    }

    /** @return array{code:string,name:string,email:string,password:string} */
    public function payload(): array
    {
        return [
            'code' => $this->string('code')->toString(),
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}
