<?php

declare(strict_types=1);

namespace App\Foundation\PaymentMethod;

use App\Foundation\PaymentMethod\ModuleServices\PaymentMethodDirectoryCore;
use App\Platform\Modules\Contracts\PaymentMethodDirectory;
use Illuminate\Support\ServiceProvider;

final class PaymentMethodServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentMethodDirectory::class, PaymentMethodDirectoryCore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
