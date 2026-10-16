<?php

declare(strict_types=1);

namespace App\Foundation\Currency;

use App\Foundation\Currency\Models\CurrencyPrecision;
use App\Foundation\Currency\ModuleServices\CurrencyRoundingCore;
use App\Foundation\Currency\Support\CurrencyLabels;
use App\Foundation\Currency\Support\MoneyPrecision;
use App\Platform\Modules\Contracts\Analytics\SharedDimensions;
use App\Platform\Modules\Contracts\CurrencyRounding;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(MoneyPrecision::class);
        $this->app->bind(CurrencyRounding::class, CurrencyRoundingCore::class);
    }

    public function boot(): void
    {
        // Cache hanya hidup dalam request/job; perubahan atau batas transaksi harus membaca ulang.
        $forget = static function (): void {
            if (app()->resolved(MoneyPrecision::class)) {
                app(MoneyPrecision::class)->forget();
            }
        };
        CurrencyPrecision::saved($forget);
        CurrencyPrecision::deleted($forget);
        Event::listen([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class], $forget);

        // Engine analitik: mata uang adalah dimensi bersama yang ditunjuk dataset module; labelnya dari sini,
        // karena Platform tidak boleh menyebut fitur Foundation.
        $this->app->make(SharedDimensions::class)->register(new CurrencyLabels);
    }
}
