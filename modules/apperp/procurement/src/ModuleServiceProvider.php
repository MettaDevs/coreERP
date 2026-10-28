<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {

        $this->app->booted(function (): void {
            Route::middleware(['web', 'auth'])
                ->prefix('api/modules/procurement')
                ->group(dirname(__DIR__).'/routes/api.php');

            Route::group([], dirname(__DIR__).'/routes/web.php');
        });
    }
}
