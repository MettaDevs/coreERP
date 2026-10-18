<?php

declare(strict_types=1);

namespace App\Platform\AddressBook\Actions;

use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\AddressBook\Models\Party;
use App\Platform\AddressBook\Support\OrganizationAddressBook;
use App\Platform\AddressBook\Support\PartyDetailData;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SavePartyDetails
{
    public function __construct(private readonly OrganizationAddressBook $addresses) {}

    /** @param array{locations: list<array<string, mixed>>, contacts: list<array<string, mixed>>} $details */
    public function create(TenantMembership $actor, Party $party, array $details): void
    {
        abort_unless($actor->tenant_id === $party->tenant_id, 404);
        if ($details['locations'] === [] && $details['contacts'] === []) {
            return;
        }
        abort_unless($actor->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE), 403);

        DB::transaction(function () use ($party, $details): void {
            $links = [];
            foreach ($details['locations'] as $index => $location) {
                try {
                    $saved = $this->addresses->saveLocation($party, Arr::except($location, ['key']));
                    if (isset($location['key'])) {
                        $links[$location['key']] = $saved['id'];
                    }
                } catch (ValidationException $failure) {
                    PartyDetailData::rethrow($failure, 'party_details.locations.'.$index);
                }
            }
            foreach ($details['contacts'] as $index => $contact) {
                try {
                    if (isset($contact['address_key'])) {
                        $contact['address_id'] = $links[$contact['address_key']];
                    }
                    $this->addresses->saveContact($party, Arr::except($contact, ['address_key']));
                } catch (ValidationException $failure) {
                    PartyDetailData::rethrow($failure, 'party_details.contacts.'.$index);
                }
            }
        });
    }
}
