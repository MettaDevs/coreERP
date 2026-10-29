<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers;

use ControlPlane\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorLogin extends Controller
{
    public function form(Request $request): Response|RedirectResponse
    {
        if ($this->pending($request) === null) {
            return redirect('/login');
        }

        return Inertia::render('two-factor-login');
    }

    public function submit(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if ($pending === null) {
            return redirect('/login')->withErrors(['email' => 'Waktu verifikasi habis. Silakan masuk kembali.']);
        }
        $input = $request->validate(['code' => ['required', 'string', 'max:255']]);
        $key = 'console-mfa:'.$pending['user_id'].'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Terlalu banyak percobaan. Tunggu satu menit sebelum mencoba lagi.']);
        }
        RateLimiter::hit($key, 60);
        $user = User::query()->whereKey($pending['user_id'])->first();
        if (! $this->allowed($user)) {
            throw ValidationException::withMessages(['code' => 'Akun tidak lagi dapat masuk. Silakan masuk kembali.']);
        }
        try {
            $response = Http::withToken((string) config('core.token'))->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->post(rtrim((string) config('core.base_url'), '/').'/api/internal/v1/operators/'.$pending['user_id'].'/verify-second-factor', ['code' => $input['code']]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['code' => 'Jawaban verifikasi belum diterima. Periksa koneksi dan gunakan kode lain jika kode pemulihan sudah terpakai.']);
        }
        if ($response->status() !== 200 || $response->json('verified') !== true || $response->json('user_id') !== $pending['user_id']) {
            throw ValidationException::withMessages(['code' => $response->status() === 422 ? 'Kode tidak cocok atau akun tidak lagi dapat masuk.' : 'Verifikasi belum dapat diselesaikan. Periksa koneksi ke Core atau coba lagi setelah satu menit.']);
        }
        $user = User::query()->whereKey($pending['user_id'])->first();
        if (! $this->allowed($user)) {
            throw ValidationException::withMessages(['code' => 'Akun tidak lagi dapat masuk. Silakan masuk kembali.']);
        }
        RateLimiter::clear($key);
        $request->session()->forget('console_mfa');
        Auth::login($user, $pending['remember']);
        $request->session()->regenerate();
        $request->session()->put('login_at', now()->getTimestamp());
        if (! config('sso.password_login')) {
            Log::warning('Konsol: akun darurat masuk dengan kata sandi.', ['email' => $user->email, 'ip' => $request->ip()]);
        }

        return redirect()->intended('/lingkungan');
    }

    /** @phpstan-assert-if-true User $user */
    private function allowed(?User $user): bool
    {
        return $user !== null && $user->account_type === 'person' && $user->operator()
            && ! $user->must_change_password && $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null
            && (config('sso.password_login') || in_array(mb_strtolower($user->email), (array) config('sso.break_glass_emails', []), true));
    }

    /** @return array{user_id: int, expires_at: int, remember: bool}|null */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('console_mfa');
        if (! is_array($pending) || ! is_int($pending['user_id'] ?? null) || ! is_int($pending['expires_at'] ?? null) || ! is_bool($pending['remember'] ?? null) || $pending['expires_at'] <= now()->getTimestamp()) {
            $request->session()->forget('console_mfa');

            return null;
        }

        return $pending;
    }
}
