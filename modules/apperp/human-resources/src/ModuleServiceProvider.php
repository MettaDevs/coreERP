<?php

declare(strict_types=1);

namespace Modules\Apperp\HumanResources;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Penyedia layanan module Human Resources.
 *
 * Module kedua yang masuk ke dalam runtime, dan itu sebabnya ia ada: satu module tidak
 * membuktikan batas antar module. Yang dibuktikan di sini bukan bahwa HR bekerja — ia sudah
 * bekerja sebagai app tersendiri — melainkan bahwa dua module dapat hidup di satu proses dan
 * satu database tanpa saling menyentuh data.
 *
 * Bentuknya sengaja meniru `management-aset` sedekat mungkin. Dua module yang menyusun dirinya
 * dengan dua cara berbeda membuat penjaga batas harus mengenali dua bentuk, dan yang kedua
 * biasanya baru ditulis setelah ada yang lupa.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            // Grup `web` diperlukan, bukan pilihan gaya: konteks module dibaca dari sesi Core,
            // dan tanpa middleware sesi `Request::session()` melempar "Session store not set on
            // request" — muncul sebagai 500, bukan sebagai 401.
            //
            // `auth` di depan `konteks-module` dengan sengaja: yang memastikan ada pengguna
            // adalah `auth`, dan yang memastikan pengguna itu berhak atas module ini adalah
            // `konteks-module`. Tanpa `auth`, permintaan tanpa sesi dijawab 403 padahal yang
            // benar 401 — soalnya identitas, bukan wewenang.
            Route::middleware(['web', 'auth'])
                ->prefix('api/modules/human-resources')
                ->group(dirname(__DIR__).'/routes/api.php');

            // Rute layar, terpisah dari rute JSON di atas dan tanpa awalan `api`.
            //
            // Ia dimuat di sini dan bukan oleh Core karena alamat layarnya milik module:
            // Core hanya menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>`
            // dari manifest, dan module yang memutuskan apa yang terjadi di alamat itu.
            Route::group([], dirname(__DIR__).'/routes/web.php');
        });
    }
}
