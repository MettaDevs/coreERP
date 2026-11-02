<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Contracts;

use App\Platform\Tenant\Support\TenantCreation;
use App\Platform\Tenant\Support\TenantCreator;
use App\Platform\Tenant\Support\TenantDetails;

interface TenantRegistrar
{
    public function handle(TenantCreator $creator, TenantDetails $details): TenantCreation;
}
