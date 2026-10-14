<?php

namespace App\Platform\AddressBook\Events;

/** Fakta dalam runtime yang sama; bukan event integrasi yang diterbitkan keluar. */
final readonly class PartyNameChanged
{
    public function __construct(public string $tenantId, public string $partyId) {}
}
