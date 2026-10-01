<?php

declare(strict_types=1);

namespace App\Foundation\NumberSequence;

use App\Foundation\NumberSequence\ModuleServices\NumberSequenceIssuerCore;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use Illuminate\Support\ServiceProvider;

/**
 * Ikatan milik fitur ini, didaftarkan oleh fitur ini sendiri.
 *
 * Platform hanya menyediakan antarmukanya di `App\Platform\Modules\Contracts` dan tidak pernah
 * menyebut pelaksananya. Daftar seluruh antarmuka yang boleh dipanggil module tetap di `CoreServices`.
 */
final class NumberSequenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NumberSequenceIssuer::class, NumberSequenceIssuerCore::class);
    }
}
