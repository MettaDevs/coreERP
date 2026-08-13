<?php

namespace App\Http\Requests\Onboarding;

use App\Concerns\PasswordValidationRules;
use App\Rules\PhoneRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

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
            'app_ids' => ['required', 'array', 'min:1'],
            'app_ids.*' => ['required', 'string', 'distinct', Rule::exists('apps', 'id')->where('status', 'available')],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => ['nullable', 'string', 'max:35', new PhoneRule()],
            'password' => $this->passwordRules(),
        ];
    }

    /** @return array{name:string,business_name:string,email:string,phone_number:?string,password:string,app_ids:list<string>} */
    public function payload(): array
    {
        $rawPhone = $this->filled('phone_number') ? $this->string('phone_number')->toString() : null;
        $normalizedPhone = null;

        if ($rawPhone !== null) {
            $phoneUtil = PhoneNumberUtil::getInstance();
            try {
                $proto = $phoneUtil->parse($rawPhone, 'ID');
                if ($phoneUtil->isValidNumber($proto)) {
                    $normalizedPhone = $phoneUtil->format($proto, PhoneNumberFormat::E164);
                } else {
                    $normalizedPhone = $rawPhone;
                }
            } catch (NumberParseException $e) {
                $normalizedPhone = $rawPhone;
            }
        }

        return [
            'name' => $this->string('name')->toString(),
            'business_name' => $this->string('business_name')->toString(),
            'app_ids' => array_values($this->collect('app_ids')->map(fn (mixed $id): string => (string) $id)->all()),
            'email' => $this->string('email')->toString(),
            'phone_number' => $normalizedPhone,
            'password' => $this->string('password')->toString(),
        ];
    }
}

