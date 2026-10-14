<?php

namespace App\Platform\AddressBook\Actions;

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Foundation\NumberSequence\Support\CoreNumberSequences;
use App\Platform\AddressBook\Models\Party;
use Illuminate\Support\Facades\DB;

final class SaveParty
{
    public function __construct(private readonly CoreNumberSequences $sequences, private readonly NumberSequenceService $numbers) {}

    /** @param array<string, mixed> $data */
    public function create(string $tenantId, array $data, string $creationKey): Party
    {
        $this->sequences->ensure($tenantId, 'core.party');

        return DB::transaction(function () use ($tenantId, $data, $creationKey): Party {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['party|'.$tenantId.'|'.$creationKey]);
            $existing = Party::withTrashed()->where('tenant_id', $tenantId)->where('creation_key', $creationKey)->first();
            if ($existing !== null) {
                abort_if($existing->trashed(), 409, 'Kiriman ini sudah menghasilkan pihak yang kini terarsip.');

                return $existing;
            }
            $issued = $this->numbers->issue(['tenant_id' => $tenantId, 'app_id' => CoreNumberSequences::APP_ID], 'core.party', 'party:'.$creationKey, null);
            $party = new Party;
            $party->forceFill([...$data, 'tenant_id' => $tenantId, 'number' => $issued['number'], 'creation_key' => $creationKey,
                'search_name' => Party::searchName($data['search_name'] ?? $data['name']), 'status' => $data['status'] ?? 'active'])->save();

            return $party;
        });
    }

    /** Pihak lama mendapat nomor sekali; pemanggil tetap memakai id database untuk relasi. */
    public function ensureNumber(Party $party): Party
    {
        if ($party->number !== null) {
            return $party;
        }
        $this->sequences->ensure($party->tenant_id, 'core.party');

        return DB::transaction(function () use ($party): Party {
            $party = Party::query()->where('tenant_id', $party->tenant_id)->lockForUpdate()->findOrFail($party->id);
            if ($party->number === null) {
                $issued = $this->numbers->issue(['tenant_id' => $party->tenant_id, 'app_id' => CoreNumberSequences::APP_ID], 'core.party', 'party:existing:'.$party->id, null);
                $party->forceFill(['number' => $issued['number']])->save();
            }

            return $party;
        });
    }
}
