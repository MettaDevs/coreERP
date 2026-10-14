<?php

namespace App\Platform\AddressBook\Contracts;

/** Nomor pihak milik Platform; penerbitnya didaftarkan lapis Foundation. */
interface PartyNumbers
{
    public function prepare(string $tenantId): void;

    public function issue(string $tenantId, string $creationKey): string;
}
