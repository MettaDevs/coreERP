<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Support;

use App\Platform\Identity\Models\User;

final readonly class TenantCreator
{
    private function __construct(
        public TenantCreationSource $source,
        public User $actor,
        public User $owner,
        private string $actorReference,
    ) {}

    public static function customer(User $principal, string $customerId): self
    {
        return new self(TenantCreationSource::Customer, $principal, $principal, $customerId);
    }

    public static function operator(User $operator, User $owner): self
    {
        return new self(TenantCreationSource::Operator, $operator, $owner, (string) $operator->id);
    }

    public static function localRegistration(User $owner): self
    {
        return new self(TenantCreationSource::LocalRegistration, $owner, $owner, (string) $owner->id);
    }

    public static function system(User $owner): self
    {
        return new self(TenantCreationSource::System, $owner, $owner, (string) $owner->id);
    }

    public function scope(): string
    {
        return $this->source->value.':'.$this->actorReference;
    }
}
