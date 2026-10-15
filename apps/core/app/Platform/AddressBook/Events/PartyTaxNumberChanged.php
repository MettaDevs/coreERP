<?php

namespace App\Platform\AddressBook\Events;

/** Perubahan dalam runtime yang sama; Foundation memperbarui akun bisnis miliknya. */
final readonly class PartyTaxNumberChanged
{
    public function __construct(public string $tenantId, public string $partyId, public ?string $taxNumber) {}
}
