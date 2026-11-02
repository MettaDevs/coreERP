<?php

declare(strict_types=1);

namespace App\Foundation\UnitOfMeasure;

use App\Foundation\UnitOfMeasure\Listeners\ProvisionUnitsForNewTenant;
use App\Foundation\UnitOfMeasure\ModuleServices\UnitOfMeasureDirectoryCore;
use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;
use App\Platform\Tenant\Events\ErpTenantInitialized;
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

    public function boot(): void
    {
        Event::listen(ErpTenantInitialized::class, ProvisionUnitsForNewTenant::class);
    }
}
