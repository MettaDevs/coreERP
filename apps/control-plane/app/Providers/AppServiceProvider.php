<?php

declare(strict_types=1);

namespace ControlPlane\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Skema tabel-tabel ini milik Core, dan hanya Core yang boleh mengubahnya.
         *
         * `preventLazyLoading` dinyalakan pada mode lokal saja, seperti kebiasaan repo. Yang lebih
         * penting di sini `preventSilentlyDiscardingAttributes`: konsol ini menulis ke tabel milik
         * aplikasi lain, jadi atribut yang diam-diam dibuang karena ia tidak ada di `$fillable`
         * adalah persis bentuk kegagalan yang akan lolos sampai ke produksi.
         */
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
        $proxies = config('core.trusted_proxies');

        if (is_string($proxies) && $proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
        }

        Model::preventSilentlyDiscardingAttributes();
        Model::preventLazyLoading($this->app->environment('local'));
    }
}
