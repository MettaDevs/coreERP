<?php

declare(strict_types=1);

namespace App\Foundation\PaymentTerm;

use App\Foundation\PaymentTerm\ModuleServices\PaymentTermDirectoryCore;
use App\Platform\Modules\Contracts\PaymentTermDirectory;
use Illuminate\Support\ServiceProvider;

final class PaymentTermServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentTermDirectory::class, PaymentTermDirectoryCore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
