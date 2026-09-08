<?php

namespace App\Providers;

use App\Reporting\Definitions\WorkOrderDocument;
use App\Reporting\Definitions\WorkOrderList;
use App\Reporting\ReportRegistry;
use Illuminate\Support\ServiceProvider;

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
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('../database/migrations'));
    }
}
