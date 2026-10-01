<?php

declare(strict_types=1);

namespace App\Foundation\NumberSequence;

use App\Foundation\NumberSequence\Listeners\PrepareNumberSequences;
use App\Foundation\NumberSequence\ModuleServices\NumberSequenceIssuerCore;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use App\Platform\Modules\Events\AppCatalogRegistered;
use App\Platform\Modules\Events\AppNumberSequenceReferencesDeclared;
use App\Platform\Modules\Events\ModuleInstallationRecorded;
use App\Platform\Tenant\Events\TenantModulesInstalled;
use Illuminate\Support\Facades\Event;
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

    /**
     * Urutan nomor disiapkan saat Platform memberi tahu tenant baru selesai dipasangi module, module
     * terpasang, atau katalog app terdaftar. Listener sinkron dan ikut transaksi pengirimnya, jadi
     * gagal di sini membatalkan langkah Platform yang memicunya.
     */
    public function boot(): void
    {
        Event::listen(TenantModulesInstalled::class, [PrepareNumberSequences::class, 'forNewTenant']);
        Event::listen(ModuleInstallationRecorded::class, [PrepareNumberSequences::class, 'forInstalledModule']);
        Event::listen(AppNumberSequenceReferencesDeclared::class, [PrepareNumberSequences::class, 'registerReferences']);
        Event::listen(AppCatalogRegistered::class, [PrepareNumberSequences::class, 'forRegisteredApp']);
    }
}
