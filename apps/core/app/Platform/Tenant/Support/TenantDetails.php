<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Support;

final readonly class TenantDetails
{
    public string $idempotencyKey;

    public function __construct(
        public string $name,
        string $idempotencyKey,
        public ?string $slug = null,
        public ?string $fixedTenantId = null,
    ) {
        $this->idempotencyKey = strtolower($idempotencyKey);
    }
}
