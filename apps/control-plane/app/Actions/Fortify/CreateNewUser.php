<?php

namespace App\Actions\Fortify;

use App\Actions\Onboarding\RegisterBusiness;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly RegisterBusiness $registerBusiness) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array{name:string,email:string,password:string,password_confirmation?:string,business_name:string,app_ids:list<string>}  $input
     */
    public function create(array $input): User
    {
        if (isset($input['email'])) {
            $input['email'] = Str::lower(trim((string) $input['email']));
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                function ($attribute, $value, $fail) {
                    $email = Str::lower(trim((string) $value));
                    $user = User::where('email', $email)->first();
                    if ($user) {
                        $count = \App\Models\TenantMembership::where('user_id', $user->id)->count();
                        if ($count >= 3) {
                            $fail('Email ini telah terdaftar untuk 3 bisnis (batas maksimal). Silakan gunakan email lain atau login.');
                        }
                    }
                },
            ],
            'app_ids' => ['required', 'array', 'min:1'],
            'app_ids.*' => ['required', 'string', 'distinct', 'exists:apps,id'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Nama pemilik akun wajib diisi.',
            'business_name.required' => 'Nama bisnis / perusahaan wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar. Silakan gunakan email lain atau login.',
            'app_ids.required' => 'Pilih minimal 1 modul aplikasi.',
            'app_ids.min' => 'Pilih minimal 1 modul aplikasi.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ])->validate();

        return $this->registerBusiness->handle([
            'name' => trim($input['name']),
            'email' => $input['email'],
            'password' => $input['password'],
            'business_name' => trim($input['business_name']),
            'app_ids' => $input['app_ids'],
        ]);
    }
}
