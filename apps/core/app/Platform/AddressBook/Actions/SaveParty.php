<?php

namespace App\Platform\AddressBook\Actions;

use App\Platform\AddressBook\Contracts\PartyNumbers;
use App\Platform\AddressBook\Models\Party;
use Illuminate\Support\Facades\DB;

final class SaveParty
{
    public function __construct(private readonly PartyNumbers $numbers) {}

    /** @param array<string, mixed> $data */
    public function create(string $tenantId, array $data, string $creationKey): Party
    {
        $this->numbers->prepare($tenantId);

        return DB::transaction(function () use ($tenantId, $data, $creationKey): Party {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['party|'.$tenantId.'|'.$creationKey]);
            $existing = Party::withTrashed()->where('tenant_id', $tenantId)->where('creation_key', $creationKey)->first();
            if ($existing !== null) {
                abort_if($existing->trashed(), 409, 'Kiriman ini sudah menghasilkan pihak yang kini terarsip.');

                return $existing;
            }
            $number = $this->numbers->issue($tenantId, $creationKey);
            $party = new Party;
            $party->forceFill([...$data, 'tenant_id' => $tenantId, 'number' => $number, 'creation_key' => $creationKey,
                'search_name' => Party::searchName($data['search_name'] ?? $data['name']), 'status' => $data['status'] ?? 'active', 'language' => $data['language'] ?? 'id'])->save();

            return $party;
        });
    }

    /** Pihak lama mendapat nomor sekali; pemanggil tetap memakai id database untuk relasi. */
    public function ensureNumber(Party $party): Party
    {
        if ($party->number !== null) {
            return $party;
        }
        $this->numbers->prepare($party->tenant_id);

        return DB::transaction(function () use ($party): Party {
            $party = Party::query()->where('tenant_id', $party->tenant_id)->lockForUpdate()->findOrFail($party->id);
            if ($party->number === null) {
                $number = $this->numbers->issue($party->tenant_id, 'existing:'.$party->id);
                $party->forceFill(['number' => $number])->save();
            }

            return $party;
        });
    }
}
