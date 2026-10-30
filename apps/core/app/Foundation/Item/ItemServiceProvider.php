<?php

declare(strict_types=1);

namespace App\Foundation\Item;

use App\Foundation\Item\ModuleServices\ItemDirectoryCore;
use App\Platform\Modules\Contracts\ItemDirectory;
use Illuminate\Support\ServiceProvider;

final class ItemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ItemDirectory::class, ItemDirectoryCore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}

