<?php

declare(strict_types=1);

namespace App\Foundation\Currency;

use App\Foundation\Currency\ModuleServices\CurrencyRoundingCore;
use App\Foundation\Currency\Support\CurrencyLabels;
use App\Platform\Modules\Contracts\Analytics\SharedDimensions;
use App\Platform\Modules\Contracts\CurrencyRounding;
use Illuminate\Support\ServiceProvider;

/**
 * Ikatan milik fitur ini, didaftarkan oleh fitur ini sendiri.
 *
 * Platform hanya menyediakan antarmukanya di `App\Platform\Modules\Contracts` dan tidak pernah
 * menyebut pelaksananya. Daftar seluruh antarmuka yang boleh dipanggil module tetap di `CoreServices`.
 */
final class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurrencyRounding::class, CurrencyRoundingCore::class);
    }

    public function boot(): void
    {
        // Engine analitik: mata uang adalah dimensi bersama yang ditunjuk dataset module; labelnya dari sini,
        // karena Platform tidak boleh menyebut fitur Foundation.
        $this->app->make(SharedDimensions::class)->register(new CurrencyLabels);
    }
}
