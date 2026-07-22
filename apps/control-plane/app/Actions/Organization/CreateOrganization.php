<?php

namespace App\Actions\Organization;

use App\Models\Organization;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrganization
{
    /** @param array{classification:string,code:string,name:string,company_code:?string,country_code:?string,operating_unit_type:?string} $data */
    public function handle(TenantMembership $actor, array $data): Organization
    {
        if (! $actor->canManageAccess()) {
            throw new AuthorizationException;
        }
        if (Organization::query()->where('tenant_id', $actor->tenant_id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Kode organisasi sudah digunakan pada tenant ini.']);
        }

        return DB::transaction(function () use ($actor, $data): Organization {
            $organization = Organization::create([
                'tenant_id' => $actor->tenant_id,
                'classification' => $data['classification'],
                'code' => $data['code'],
                'name' => $data['name'],
                'status' => 'active',
            ]);

            if ($data['classification'] === 'legal_entity') {
                $organization->legalEntity()->create([
                    'company_code' => $data['company_code'],
                    'country_code' => strtoupper((string) $data['country_code']),
                ]);
            } else {
                $organization->operatingUnit()->create(['type' => $data['operating_unit_type']]);
            }

            return $organization->load(['legalEntity', 'operatingUnit']);
        });
    }
}
