<?php

namespace App\Providers;

use App\Models\Passkey;
use App\Models\User;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\ControlPlane\OutboundGuard;
use App\Support\CurrentWorkspace;
use App\Support\DataPolicyAccessResolver;
use App\Support\License\SiteLicense;
use App\Support\Observabilitas\PelaporKesalahan;
use App\Support\ParameterWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;

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

        // Alasan yang sama untuk parameter workflow: jawabannya tidak berubah di tengah satu
        // permintaan, dan sebuah workflow bercabang akan menanyakannya berkali-kali.
        $this->app->scoped(ParameterWorkflow::class);

        /*
         * Scoped, dan itu yang membuat `lupakan()` pada kelas itu jarang diperlukan.
         *
         * Jawabannya ditanyakan ulang oleh setiap titik yang menjaga sambungan keluar, dan
         * setiap pertanyaan berarti satu query kalau instansnya baru tiap kali. Scoped juga
         * yang menjaga ingatannya tidak menyeberang: pekerja antrean yang memungut job
         * berikutnya mendapat ikatan yang bersih, persis seperti permintaan HTTP berikutnya.
         */
        $this->app->scoped(ActiveEnvironment::class);

        // Berkas lisensi dibaca sekali per permintaan, bukan sekali per pembaca — ada empat pintu app
        // dan satu middleware kunci yang menanyakannya. Scoped, bukan singleton, supaya lisensi baru
        // yang dipasang agen membuka kunci pada permintaan berikutnya tanpa menunggu pekerja PHP
        // diganti. Alasan lengkapnya di kelas itu.
        $this->app->scoped(SiteLicense::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Proxy tepercaya disetel DI SINI, bukan di `bootstrap/app.php`.
         *
         * Closure `withMiddleware()` dijalankan `afterResolving(HttpKernel::class)`, dan kernel
         * di-resolve SEBELUM `LoadEnvironmentVariables` berjalan. Akibatnya `env()` di dalam closure
         * itu selalu memulangkan null, dan pemanggilan `trustProxies()` di sana tidak pernah
         * melakukan apa pun. Ia terbaca benar dan terbukti mati.
         *
         * Gejalanya hanya muncul di belakang proxy: Laravel membaca alamat dari koneksi ke proxy —
         * yang memang `http` — lalu menerbitkan setiap pengalihan sebagai `http://`. Peramban
         * dilempar ke porta 80, dan yang terlihat bukan "salah setel proxy" melainkan galat milik
         * apa pun yang kebetulan mendengar di sana.
         *
         * Ditemukan dengan membukanya lewat Traefik, bukan oleh test.
         */
        $proxies = config('coreerp.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
        }

        // Prevent touch() utime warning from crashing Blade view rendering on containerized environments
        set_error_handler(function ($severity, $message) {
            if (str_contains($message, 'touch(): Utime failed')) {
                return true;
            }

            return false;
        }, E_WARNING);

        /*
         * Passkey dibaca dari database pusat, bukan dari database lingkungan.
         *
         * Dipasang di sini karena satu-satunya pintunya milik paket, dan alasan lengkapnya beserta
         * kenapa menandai `User` saja tidak cukup ada di {@see Passkey}. Tanpa baris ini, pelanggan
         * yang masuk dengan passkey dari alamat lingkungannya tidak menemukan passkey miliknya.
         */
        Passkeys::usePasskeyModel(Passkey::class);

        $this->configureDefaults();
        $this->hentikanPenerusanLogKeOtel();

        // Dipasang tanpa syarat, termasuk on-prem dan di dalam test. Yang menentukan apakah ia
        // menolak sesuatu adalah baris `environments`, bukan pemasangannya — dan selama satu
        // tenant hanya punya produksi, ia tidak pernah menolak apa pun.
        OutboundGuard::install();

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

        // Per klien integrasi, dikunci pada id di depan token — bukan per alamat IP, karena satu
        // aplikasi finance biasanya memanggil dari satu alamat dan yang perlu dibatasi adalah
        // kliennya. Permintaan tanpa token dibatasi per alamat supaya tebakan token tetap murah
        // untuk ditolak.
        RateLimiter::for('integration-client', fn (Request $request): Limit => Limit::perMinute(
            (int) config('coreerp.integration_api_rate_limit', 120)
        )->by(self::kunciKlienIntegrasi($request)));

        // Rute yang dibaca module dan sistem luar sekaligus memakai kunci milik jalur yang dipilih.
        RateLimiter::for('internal-caller', fn (Request $request): Limit => $request->hasHeader('X-CoreERP-App-Id')
            ? Limit::perMinute((int) config('coreerp.internal_api_rate_limit', 600))->by(implode(':', [
                $request->header('X-CoreERP-App-Id', 'unknown'),
                $request->header('X-CoreERP-Tenant-Id', 'unknown'),
            ]))
            : Limit::perMinute((int) config('coreerp.integration_api_rate_limit', 120))->by(self::kunciKlienIntegrasi($request)));
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

    /**
     * Menghentikan `opentelemetry-auto-laravel` meneruskan setiap panggilan `Log::` ke OTLP.
     *
     * Paket itu memasang `LogWatcher`, yang mendengarkan `MessageLogged` dan mengubah setiap
     * catatan log menjadi satu catatan OTLP. Akibatnya satu kesalahan tiba di SigNoz sebagai
     * **dua** catatan: log exception bawaan Laravel, dan laporan yang dikirim
     * {@see PelaporKesalahan} dengan sengaja.
     *
     * Yang dipertahankan adalah yang kedua, dan itu bukan sekadar soal jumlah. Laporan terkurasi
     * membawa tenant, module, pengguna, batas organisasi, SQL yang gagal, dan `trace_id` sebagai
     * atribut yang bisa disaring; salinan dari `LogWatcher` membawa empat atribut dan tidak satu
     * pun di antaranya bisa dipakai menyaring. Menyimpan keduanya berarti membayar dua kali
     * untuk satu kejadian, dan yang lebih miskin justru yang muncul lebih dulu saat dicari.
     *
     * Panggilan `Log::` biasa tetap berjalan seperti sedia kala dan tetap masuk `laravel.log`.
     * Yang hilang hanya penerusannya ke SigNoz — dan sampai basis kode ini benar-benar menulis
     * log terstruktur di jalur permintaan (lihat `LIFE-14`), yang diteruskan itu hampir tidak
     * ada isinya.
     *
     * Mengembalikannya cukup dengan menghapus pemanggilan metode ini.
     */
    private function hentikanPenerusanLogKeOtel(): void
    {
        // Tidak ada pendengar `MessageLogged` lain di basis kode ini — sudah diperiksa — jadi
        // melupakan seluruh pendengarnya setara dengan melepas satu pendengar milik paket itu.
        // Kalau suatu saat CoreERP menambah pendengarnya sendiri, baris ini harus berubah
        // menjadi pelepasan yang lebih tepat sasaran.
        Event::forget(MessageLogged::class);
    }

    /** Id klien di depan token `Bearer <id>.<rahasia>`, atau alamat IP bila tidak ada token. */
    private static function kunciKlienIntegrasi(Request $request): string
    {
        $token = (string) $request->bearerToken();
        $id = str_contains($token, '.') ? strstr($token, '.', true) : '';

        return is_string($id) && $id !== '' ? 'klien:'.$id : 'ip:'.$request->ip();
    }
}
