<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

class PhoneRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! is_string($value)) {
            $fail('Nomor telepon tidak valid. Masukkan nomor telepon yang benar.');
            return;
        }

        $phoneUtil = PhoneNumberUtil::getInstance();

        try {
            // Parse with ID (Indonesia) as default country region if national format
            $phoneNumberProto = $phoneUtil->parse($value, 'ID');
            
            if (! $phoneUtil->isValidNumber($phoneNumberProto)) {
                $fail('Nomor telepon tidak valid. Masukkan nomor telepon yang benar.');
            }
        } catch (NumberParseException $e) {
            $fail('Nomor telepon tidak valid. Masukkan nomor telepon yang benar.');
        }
    }
}
