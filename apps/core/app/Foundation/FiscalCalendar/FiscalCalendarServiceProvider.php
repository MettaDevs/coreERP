<?php

declare(strict_types=1);

namespace App\Foundation\FiscalCalendar;

use App\Foundation\FiscalCalendar\ModuleServices\FiscalCalendarDirectoryCore;
use App\Platform\Modules\Contracts\FiscalCalendarDirectory;
use Illuminate\Support\ServiceProvider;

/**
 * Ikatan milik fitur ini, didaftarkan oleh fitur ini sendiri.
 *
 * Platform hanya menyediakan antarmukanya di `App\Platform\Modules\Contracts` dan tidak pernah
 * menyebut pelaksananya. Daftar seluruh antarmuka yang boleh dipanggil module tetap di `CoreServices`.
 */
final class FiscalCalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FiscalCalendarDirectory::class, FiscalCalendarDirectoryCore::class);
    }
}
