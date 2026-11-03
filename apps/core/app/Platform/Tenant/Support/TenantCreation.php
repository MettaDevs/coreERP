<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Support;

use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;

final readonly class TenantCreation
{
    public function __construct(
        public Tenant $tenant,
        public TenantMembership $membership,
        public User $principal,
        public bool $replayed = false,
    ) {}
}
