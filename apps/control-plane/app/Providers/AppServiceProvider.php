<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\DataPolicyAccessResolver;
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
        /*
         * Konteks permintaan dihitung sekali, bukan sekali per penanya.
         *
         * Keduanya ditanyai berkali-kali dalam satu permintaan oleh pihak yang berbeda, dan tiap
         * pemanggilan dulu berujung query baru dengan parameter yang sama persis. Diukur pada satu
         * permintaan daftar module yang paling sederhana: **22 query, hanya satu di antaranya
         * mengambil data yang diminta.** `tenant_memberships` dibaca empat kali, lingkup kebijakan
         * enam kali, `organizations` lima kali.
         *
         * `scoped()`, bukan `singleton()`. Bedanya menentukan pada pekerja yang hidup lama: ikatan
         * scoped dibuang di antara permintaan, sedangkan singleton akan membawa keanggotaan
         * pengguna sebelumnya ke permintaan berikutnya — kesalahan yang tidak pernah gagal, hanya
         * salah.
         */
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->scoped(DataPolicyAccessResolver::class);
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
            'manage-report-layouts',
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
