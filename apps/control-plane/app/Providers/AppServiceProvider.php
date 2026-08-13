<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define(
            'manage-access',
            fn (User $user): bool => app(CurrentWorkspace::class)->membership(request())?->canManageAccess() ?? false,
        );
        Gate::define(
            'manage-number-sequences',
            fn (User $user): bool => app(CurrentWorkspace::class)->membership(request())?->canManageAccess() ?? false,
        );
        Gate::define(
            'manage-reference-data',
            fn (User $user): bool => app(CurrentWorkspace::class)->membership(request())?->canManageAccess() ?? false,
        );
        Gate::define('monitor-identities', fn (User $user): bool => $user->providerAccess()->where('role', 'provider_admin')->exists());
        Gate::define('manage-app-catalog', fn (User $user): bool => $user->providerAccess()->where('role', 'provider_admin')->exists());

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            try {
                \App\Models\SecurityActivity::create([
                    'user_id' => $event->user->id,
                    'type' => 'login_success',
                    'title' => 'Login Berhasil',
                    'detail' => request()->header('User-Agent', 'Web Browser') . ' (' . (request()->ip() ?? '127.0.0.1') . ')',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->header('User-Agent'),
                    'status' => 'success',
                ]);
            } catch (\Throwable $e) {
                // Ignore fallback if table not migrated yet
            }
        });

        Event::listen(\Illuminate\Auth\Events\Failed::class, function (\Illuminate\Auth\Events\Failed $event): void {
            if ($event->user) {
                try {
                    \App\Models\SecurityActivity::create([
                        'user_id' => $event->user->id,
                        'type' => 'login_failed',
                        'title' => 'Login Gagal',
                        'detail' => request()->header('User-Agent', 'Web Browser') . ' (' . (request()->ip() ?? '127.0.0.1') . ') — Kata sandi salah',
                        'ip_address' => request()->ip(),
                        'user_agent' => request()->header('User-Agent'),
                        'status' => 'warning',
                    ]);
                } catch (\Throwable $e) {
                    // Ignore fallback
                }
            }
        });

        // Keyed per app and tenant so one noisy app cannot starve another, and so a stolen token cannot burn a
        // tenant's number range as fast as the network allows. Credential checks are bcrypt, so this also bounds
        // the CPU an unauthenticated caller can spend.
        RateLimiter::for('internal-app', fn (Request $request): Limit => Limit::perMinute(
            (int) config('coreerp.internal_api_rate_limit', 600)
        )->by(implode(':', [
            $request->header('X-CoreERP-App-Id', 'unknown'),
            $request->header('X-CoreERP-Tenant-Id', 'unknown'),
        ])));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(function (): ?Password {
            if (! app()->isProduction()) {
                return null;
            }

            $password = Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            return config('coreerp.password_breach_check', true)
                ? $password->uncompromised()
                : $password;
        });
    }
}
