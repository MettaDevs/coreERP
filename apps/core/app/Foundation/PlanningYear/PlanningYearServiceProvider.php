<?php

declare(strict_types=1);

namespace App\Foundation\PlanningYear;

use App\Foundation\PlanningYear\ModuleServices\PlanningYearDirectoryCore;
use App\Platform\Modules\Contracts\PlanningYearDirectory;
use Illuminate\Support\ServiceProvider;

final class PlanningYearServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PlanningYearDirectory::class, PlanningYearDirectoryCore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
