<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http\Controllers\Internal;

use App\Platform\Identity\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final class OperatorSecondFactorController
{
    public function __invoke(Request $request, string $user): JsonResponse
    {
        $key = 'operator-second-factor:'.(int) $user;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Terlalu banyak percobaan. Coba lagi sebentar.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        $input = $request->validate(['code' => ['required', 'string', 'max:255']]);
        $verified = (new User)->getConnection()->transaction(function () use ($user, $input): bool {
            $operator = User::query()->whereKey($user)->lockForUpdate()->first();
            if (! $operator || $operator->account_type !== User::PERSON || $operator->must_change_password
                || ! $operator->providerAccess()->where('role', 'provider_admin')->exists()
                || ! $operator->two_factor_secret || ! $operator->two_factor_confirmed_at) {
                return false;
            }

            try {
                $encrypter = Fortify::currentEncrypter();
                $secret = $encrypter->decrypt($operator->two_factor_secret);
                if (is_string($secret) && preg_match('/^\d{6}$/D', $input['code']) === 1
                    && app(TwoFactorAuthenticationProvider::class)->verify($secret, $input['code'])) {
                    return true;
                }

                if (! $operator->two_factor_recovery_codes) {
                    return false;
                }
                $codes = json_decode($encrypter->decrypt($operator->two_factor_recovery_codes), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($codes)) {
                    return false;
                }
                foreach ($codes as $index => $code) {
                    if (is_string($code) && hash_equals($code, $input['code'])) {
                        unset($codes[$index]);
                        $operator->forceFill([
                            'two_factor_recovery_codes' => $encrypter->encrypt(json_encode(array_values($codes), JSON_THROW_ON_ERROR)),
                        ])->save();

                        return true;
                    }
                }
            } catch (DecryptException|\JsonException) {
                return false;
            }

            return false;
        });

        return $verified
            ? response()->json(['verified' => true, 'user_id' => (int) $user])
            : response()->json(['message' => 'Kode verifikasi tidak dapat digunakan.'], 422);
    }
}
