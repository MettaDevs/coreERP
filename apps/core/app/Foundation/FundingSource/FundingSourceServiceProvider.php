<?php

declare(strict_types=1);

namespace App\Foundation\FundingSource;

use App\Foundation\FundingSource\ModuleServices\FundingSourceDirectoryCore;
use App\Platform\Modules\Contracts\FundingSourceDirectory;
use Illuminate\Support\ServiceProvider;

final class FundingSourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FundingSourceDirectory::class, FundingSourceDirectoryCore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
