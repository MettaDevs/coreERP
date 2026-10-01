<?php

declare(strict_types=1);

namespace App\Foundation\UnitOfMeasure\Listeners;

use App\Foundation\UnitOfMeasure\Actions\ProvisionDefaultUnitsOfMeasure;
use App\Platform\Tenant\Events\TenantCreated;

/**
 * Satuan bawaan untuk tenant yang baru lahir, di dalam transaksi pendaftarannya.
 */
final class ProvisionUnitsForNewTenant
{
    public function __construct(private readonly ProvisionDefaultUnitsOfMeasure $units) {}

    public function handle(TenantCreated $event): void
    {
        $this->units->forTenant($event->tenantId);
    }
}
