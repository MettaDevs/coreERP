<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement;

use Illuminate\Support\ServiceProvider;

final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__).'/routes/web.php');
        $this->loadRoutesFrom(dirname(__DIR__).'/routes/api.php');
    }
}
