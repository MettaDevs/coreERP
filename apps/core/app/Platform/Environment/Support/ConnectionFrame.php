<?php

declare(strict_types=1);

namespace App\Platform\Environment\Support;

/** @internal */
final readonly class ConnectionFrame
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        public array $configuration,
        public string $target,
        public bool $disconnect,
        public bool $disconnectMigration,
        public bool $hadEnvironment,
        public ?string $environmentId,
    ) {}
}
