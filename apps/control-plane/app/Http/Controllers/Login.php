<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers;

use ControlPlane\Sso\IdentityProvider;
use ControlPlane\Sso\SsoFailure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Masuk memakai akun Core yang sama — lewat SSO, atau lewat kata sandi.
 *
 * Konsol ini tidak punya jalur pendaftaran, undangan, maupun setel ulang kata sandi — semuanya
 * tetap di Core dan tidak disentuh.
 *
 * ## Pintu kata sandi
 *
 * Sebelum SSO ada, pintu ini tidak punya batas percobaan sama sekali: panel yang dapat membuat
 * tenant dan memperbarui setiap database pelanggan dapat ditebak kata sandinya tanpa henti. Kini
 * lima percobaan per email dan alamat per menit.
 *
 * Pintunya dapat ditutup lewat `CONSOLE_PASSWORD_LOGIN=false`. Yang tetap boleh lewat hanya akun
 * darurat di `CONSOLE_BREAK_GLASS_EMAILS` — untuk hari ketika penyedia identitas sendiri mati — dan
 * setiap pemakaiannya dicatat sebagai peringatan. Microsoft menyarankan pola yang sama untuk
 * Entra ID: akun akses darurat yang tidak bergantung pada penyedia identitas, dipantau setiap kali
 * dipakai.
 */
class Login extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function form(Request $request, IdentityProvider $provider): InertiaResponse
    {
        return Inertia::render('login', [
            'ssoAvailable' => $provider->isConfigured(),
            'passwordLoginOpen' => (bool) config('sso.password_login'),
            'ssoError' => SsoFailure::messageFor($request->query('sso_error')),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower($input['email']);
        $throttleKey = 'console-login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => sprintf('Terlalu banyak percobaan. Coba lagi dalam %d detik.', RateLimiter::availableIn($throttleKey)),
            ]);
        }

        $passwordLoginOpen = (bool) config('sso.password_login');
        /** @var list<string> $breakGlass */
        $breakGlass = (array) config('sso.break_glass_emails', []);

        // Diperiksa sebelum kata sandinya dicoba, dan dengan pesan yang sama untuk email mana pun.
        // Pesan yang berbeda antara "akun ini ada" dan "akun ini tidak ada" menjadikan formulir ini
        // alat pencari akun.
        if (! $passwordLoginOpen && ! in_array($email, $breakGlass, true)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'Masuk dengan kata sandi ditutup di konsol ini. Gunakan Masuk dengan SSO.',
            ]);
        }

        if (! Auth::attempt(['email' => $input['email'], 'password' => $input['password']], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            // Pesannya sengaja tidak membedakan "email tidak ada" dari "kata sandi salah".
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        if (! $passwordLoginOpen) {
            Log::warning('Konsol: akun darurat masuk dengan kata sandi.', ['email' => $email, 'ip' => $request->ip()]);
        }

        $request->session()->regenerate();
        $request->session()->put('login_at', now()->getTimestamp());

        return redirect()->intended('/lingkungan');
    }
}
