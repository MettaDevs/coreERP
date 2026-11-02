<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Events;

final readonly class ErpTenantInitialized
{
    public function __construct(public string $tenantId) {}
}
