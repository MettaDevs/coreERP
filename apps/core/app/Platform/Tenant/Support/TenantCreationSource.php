<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Support;

enum TenantCreationSource: string
{
    case Customer = 'customer';
    case Operator = 'operator';
    case LocalRegistration = 'local_registration';
    case System = 'system';
}
