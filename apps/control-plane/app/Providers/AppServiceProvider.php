<?php

declare(strict_types=1);

namespace ControlPlane\Providers;

use Illuminate\Database\Eloquent\Model;
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
        Model::preventSilentlyDiscardingAttributes();
        Model::preventLazyLoading($this->app->environment('local'));
    }
}
