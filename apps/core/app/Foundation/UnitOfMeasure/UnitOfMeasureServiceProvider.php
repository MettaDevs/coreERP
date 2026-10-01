<?php

declare(strict_types=1);

namespace App\Foundation\UnitOfMeasure;

use App\Foundation\UnitOfMeasure\Listeners\ProvisionUnitsForNewTenant;
use App\Foundation\UnitOfMeasure\ModuleServices\UnitOfMeasureDirectoryCore;
use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;
use App\Platform\Tenant\Events\TenantCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Ikatan milik fitur ini, didaftarkan oleh fitur ini sendiri.
 *
 * Platform hanya menyediakan antarmukanya di `App\Platform\Modules\Contracts` dan tidak pernah
 * menyebut pelaksananya. Daftar seluruh antarmuka yang boleh dipanggil module tetap di `CoreServices`.
 */
final class UnitOfMeasureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UnitOfMeasureDirectory::class, UnitOfMeasureDirectoryCore::class);
    }

    /**
     * Satuan bawaan disiapkan saat tenant lahir, sinkron di dalam transaksi pendaftarannya: gagal
     * di sini membatalkan seluruh pendaftaran.
     */
    public function boot(): void
    {
        Event::listen(TenantCreated::class, ProvisionUnitsForNewTenant::class);
    }
}
