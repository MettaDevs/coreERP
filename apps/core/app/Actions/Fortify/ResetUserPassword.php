<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // Jalur lupa-sandi juga menggantikan kata sandi sementara, jadi penandanya ikut padam di
        // sini. Kalau tidak, pemilik yang kehilangan sandi sementaranya lalu menyetel ulang lewat
        // email akan tetap terkurung di layar ganti kata sandi — dengan sandi yang sudah tidak
        // diketahui siapa pun lagi.
        $user->forceFill([
            'password' => $input['password'],
            'must_change_password' => false,
        ])->save();
    }
}
