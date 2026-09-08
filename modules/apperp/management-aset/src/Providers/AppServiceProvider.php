<?php

namespace Modules\Apperp\ManagementAset\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Apperp\ManagementAset\Reporting\Definitions\WorkOrderDocument;
use Modules\Apperp\ManagementAset\Reporting\Definitions\WorkOrderList;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Laporan yang dikenal app ini. Menambah laporan = menambah satu kelas definisi
        // dan mendaftarkannya di sini; layout, ekspor, dan UI-nya mengikuti otomatis.
        $this->app->singleton(ReportRegistry::class, function (): ReportRegistry {
            $registry = new ReportRegistry;
            $registry->register(new WorkOrderDocument);
            $registry->register(new WorkOrderList);

            return $registry;
        });
    }

    /**
     * Migration module dijalankan ModuleMigrator milik Core, bukan didaftarkan di sini.
     *
     * Sebelumnya `boot()` memanggil `loadMigrationsFrom()` dengan jalur relatif yang menaiki satu
     * folder dari `base_path()`. Jalur itu masuk akal ketika module masih aplikasi tersendiri dengan
     * `base_path()` miliknya; di dalam runtime Core ia menunjuk ke luar folder Core dan tidak
     * menemukan apa pun. Yang
     * lebih penting: migration module harus dicatat per module supaya pemasangan dan pencabutan
     * per tenant bisa dilacak, dan itu yang dikerjakan `ModuleMigrator`.
     */
}
