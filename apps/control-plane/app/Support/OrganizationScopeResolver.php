<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationHierarchy;
use Illuminate\Validation\ValidationException;

final class OrganizationScopeResolver
{
    /** @param array{organization_id:?string,hierarchy_id:?string,include_descendants:bool} $data
     * @return array{organization_id:?string,hierarchy_id:?string,hierarchy_version_id:?string,include_descendants:bool}
     */
    public function resolve(string $tenantId, array $data): array
    {
        if ($data['organization_id'] === null) {
            if ($data['include_descendants'] || $data['hierarchy_id'] !== null) {
                throw ValidationException::withMessages(['organization_id' => 'Pilih organisasi sebelum menyertakan turunannya.']);
            }

            return ['organization_id' => null, 'hierarchy_id' => null, 'hierarchy_version_id' => null, 'include_descendants' => false];
        }

        $organization = Organization::query()->where('tenant_id', $tenantId)->where('status', 'active')->find($data['organization_id']);
        if (! $organization) {
            throw ValidationException::withMessages(['organization_id' => 'Organisasi tidak tersedia pada tenant aktif.']);
        }
        if (! $data['include_descendants']) {
            return ['organization_id' => $organization->id, 'hierarchy_id' => null, 'hierarchy_version_id' => null, 'include_descendants' => false];
        }

        $hierarchy = OrganizationHierarchy::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['versions' => fn ($query) => $query
                ->where('status', 'published')
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')])
            ->find($data['hierarchy_id']);
        $version = $hierarchy?->versions->first();
        $containsOrganization = $version?->nodes()->where('organization_id', $organization->id)->exists() ?? false;
        if (! $hierarchy || ! $version || ! $containsOrganization) {
            throw ValidationException::withMessages(['hierarchy_id' => 'Pilih hierarchy aktif yang memuat organisasi tersebut.']);
        }

        return [
            'organization_id' => $organization->id,
            'hierarchy_id' => $hierarchy->id,
            'hierarchy_version_id' => $version->id,
            'include_descendants' => true,
        ];
    }
}
