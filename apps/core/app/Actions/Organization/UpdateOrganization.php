<?php

namespace App\Actions\Organization;

use App\Models\Organization;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateOrganization
{
    /** @param array{name:string,company_code:?string,country_code:?string,operating_unit_type:?string} $data */
    public function handle(TenantMembership $actor, Organization $organization, array $data): Organization
    {
        if (! $actor->canManageAccess() || $organization->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($organization, $data): Organization {
            $organization->update(['name' => $data['name']]);

            if ($organization->classification === 'legal_entity') {
                $organization->legalEntity()->update([
                    'company_code' => $data['company_code'],
                    'country_code' => $data['country_code'],
                ]);
            } else {
                $organization->operatingUnit()->update(['type' => $data['operating_unit_type']]);
            }

            return $organization->fresh(['legalEntity', 'operatingUnit']);
        });
    }
}
