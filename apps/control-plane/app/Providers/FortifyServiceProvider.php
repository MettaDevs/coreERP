<?php

namespace App\Providers;

/* @chisel-registration */

use App\Actions\Fortify\CreateNewUser;
/* @end-chisel-registration */
use App\Actions\Fortify\ResetUserPassword;
use App\Models\CoreApp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PasswordResetResponseContract::class, function () {
            return new class implements PasswordResetResponseContract {
                public function toResponse($request) {
                    return redirect()->route('login')->with('status', 'Password berhasil diperbarui! Silakan login dengan password baru Anda.');
                }
            };
        });

        $this->app->singleton(FailedPasswordResetResponseContract::class, function () {
            return new class implements FailedPasswordResetResponseContract {
                public function toResponse($request) {
                    return redirect()->route('password.request')->withErrors([
                        'email' => 'Gagal memperbarui kata sandi. Token tidak valid atau email salah.',
                    ]);
                }
            };
        });

        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponseContract::class, function () {
            return new class implements SuccessfulPasswordResetLinkRequestResponseContract {
                public function toResponse($request) {
                    return redirect()->route('password.request')->with('status', 'Link reset kata sandi telah dikirimkan ke email Anda.');
                }
            };
        });

        $this->app->singleton(FailedPasswordResetLinkRequestResponseContract::class, function ($app, $parameters = []) {
            return new class ($parameters['status'] ?? null) implements FailedPasswordResetLinkRequestResponseContract {
                protected $status;
                public function __construct($status = null) {
                    $this->status = $status;
                }
                public function toResponse($request) {
                    $msg = $this->status === 'passwords.throttled'
                        ? 'Harap tunggu beberapa saat sebelum meminta link reset kata sandi lagi.'
                        : 'Alamat email tidak ditemukan dalam sistem kami.';
                    return redirect()->route('password.request')->withErrors(['email' => $msg]);
                }
            };
        });


        $this->app->singleton(LoginResponseContract::class, \App\Http\Responses\LoginResponse::class);

        $this->app->singleton(RegisterResponseContract::class, function () {
            return new class implements RegisterResponseContract {
                public function toResponse($request)
                {
                    auth()->guard()->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->with('status', 'Registrasi berhasil! Silakan masuk dengan email dan kata sandi Anda.');
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        /* @chisel-registration */
        Fortify::createUsersUsing(CreateNewUser::class);
        /* @end-chisel-registration */

        Fortify::authenticateUsing(function (Request $request) {
            $email = Str::lower(trim((string) $request->email));

            $request->validate([
                'email' => ['required', 'string', 'email:filter'],
                'password' => ['required', 'string'],
            ], [
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
                'password.required' => 'Password wajib diisi.',
            ]);

            $user = User::where('email', $email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Email atau password salah.'],
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        /* @chisel-email-verification */
        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));
        /* @end-chisel-email-verification */

        /* @chisel-registration */
        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'apps' => CoreApp::query()
                ->where('status', 'available')
                ->orderBy('name')
                ->get(['id', 'name', 'description'])
                ->map(fn (CoreApp $app): array => [
                    'id' => $app->id,
                    'name' => $app->name,
                    'description' => $app->description ?? '',
                ])->values(),
        ]));
        /* @end-chisel-registration */

        /* @chisel-2fa */
        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));
        /* @end-chisel-2fa */

        /* @chisel-password-confirmation */
        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
        /* @end-chisel-password-confirmation */
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        /* @chisel-2fa */
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
        /* @end-chisel-2fa */

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute((int) config('coreerp.login_rate_limit', 15))->by($throttleKey);
        });

        /* @chisel-passkeys */
        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
        /* @end-chisel-passkeys */
    }
}
