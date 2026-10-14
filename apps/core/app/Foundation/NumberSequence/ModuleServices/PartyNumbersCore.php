<?php

namespace App\Foundation\NumberSequence\ModuleServices;

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Support\CoreNumberSequences;
use App\Platform\AddressBook\Contracts\PartyNumbers;

final class PartyNumbersCore implements PartyNumbers
{
    public function __construct(private readonly CoreNumberSequences $sequences, private readonly NumberSequenceService $numbers) {}

    public function prepare(string $tenantId): void
    {
        $this->sequences->ensure($tenantId, 'core.party');
    }

    public function issue(string $tenantId, string $creationKey): string
    {
        return $this->numbers->issue(['tenant_id' => $tenantId, 'app_id' => CoreNumberSequences::APP_ID], 'core.party', 'party:'.$creationKey, null)['number'];
    }
}
