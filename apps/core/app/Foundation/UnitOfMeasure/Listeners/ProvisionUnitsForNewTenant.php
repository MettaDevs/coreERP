<?php

declare(strict_types=1);

namespace App\Foundation\UnitOfMeasure\Listeners;

use App\Foundation\UnitOfMeasure\Actions\ProvisionDefaultUnitsOfMeasure;
use App\Platform\Tenant\Events\ErpTenantInitialized;

final class ProvisionUnitsForNewTenant
{
    public function __construct(private readonly ProvisionDefaultUnitsOfMeasure $units) {}

    public function handle(ErpTenantInitialized $event): void
    {
        $this->units->forTenant($event->tenantId);
    }
}
