<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Support;

final readonly class ErpTenantSetup
{
    /** @param list<string> $appIds */
    public function __construct(
        public array $appIds,
        public string $kind = 'production',
        public string $hosting = 'provider',
        public ?string $expiresAt = null,
    ) {}
}
