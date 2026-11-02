<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane;

use App\Platform\ControlPlane\Tenants\CreateTenant;
use App\Platform\Tenant\Contracts\TenantRegistrar;
use Illuminate\Support\ServiceProvider;

final class ControlPlaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TenantRegistrar::class, CreateTenant::class);
    }
}
