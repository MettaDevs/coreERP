<?php

namespace App\Support;

use App\Models\AppDataPolicy;
use App\Models\Organization;
use App\Models\OrganizationHierarchy;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DataPolicyScopeResolver
{
    /**
     * @param  array{policy_code:string,legal_entity_id:?string,organization_id:?string,hierarchy_id:?string,include_descendants:bool,unrestricted?:bool}  $data
     * @return array{policy_code:string,legal_entity_id:?string,organization_id:?string,hierarchy_id:?string,hierarchy_version_id:?string,include_descendants:bool}
     */
    public function resolve(string $tenantId, Role $role, array $data): array
    {
        $policy = AppDataPolicy::query()->find($data['policy_code']);
        if (! $policy || ! $this->roleUsesPolicy($tenantId, $role, $policy)) {
            throw ValidationException::withMessages(['policy_code' => 'Policy data tidak tersedia untuk tanggung jawab bisnis yang dipilih.']);
        }

        // Grant tanpa dimensi berarti seluruh organisasi. Ia harus dinyatakan
        // eksplisit agar tidak tertukar dengan form yang belum diisi; dimensi
        // wajib policy sengaja dilewati karena tidak ada batas yang dipasang.
        if ($data['unrestricted'] ?? false) {
            return [
                'policy_code' => $policy->code,
                'legal_entity_id' => null,
                'organization_id' => null,
                'hierarchy_id' => null,
                'hierarchy_version_id' => null,
                'include_descendants' => false,
            ];
        }

        $legalEntityId = $this->legalEntityId($tenantId, $data['legal_entity_id'], $policy->requires_legal_entity);
        $organization = $this->organization(
            $tenantId,
            $data['organization_id'],
            $policy->requires_operating_unit,
        );

        if ($organization?->classification === 'legal_entity' && $legalEntityId !== null && $organization->id !== $legalEntityId) {
            throw ValidationException::withMessages(['organization_id' => 'Node badan hukum harus sama dengan badan hukum pada batas akses.']);
        }

        if ($data['include_descendants'] && ! $policy->allows_descendants) {
            throw ValidationException::withMessages(['include_descendants' => 'Policy data ini tidak mendukung akses ke organisasi turunan.']);
        }

        if (! $data['include_descendants']) {
            return [
                'policy_code' => $policy->code,
                'legal_entity_id' => $legalEntityId,
                'organization_id' => $organization?->id,
                'hierarchy_id' => null,
                'hierarchy_version_id' => null,
                'include_descendants' => false,
            ];
        }

        if (! $organization) {
            throw ValidationException::withMessages(['organization_id' => 'Pilih organisasi sebelum menyertakan turunannya.']);
        }

        $hierarchy = OrganizationHierarchy::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['versions' => fn ($query) => $query
                ->where('status', 'published')
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->orderByDesc('version_number')])
            ->find($data['hierarchy_id']);
        $version = $hierarchy?->versions->first();
        $containsOrganization = $version?->nodes()->where('organization_id', $organization->id)->exists() ?? false;
        if (! $hierarchy || ! $version || ! $containsOrganization) {
            throw ValidationException::withMessages(['hierarchy_id' => 'Pilih susunan organisasi aktif yang memuat organisasi tersebut.']);
        }

        return [
            'policy_code' => $policy->code,
            'legal_entity_id' => $legalEntityId,
            'organization_id' => $organization->id,
            'hierarchy_id' => $hierarchy->id,
            'hierarchy_version_id' => $version->id,
            'include_descendants' => true,
        ];
    }

    /**
     * @param  list<array{policy_code:string,legal_entity_id:?string,organization_id:?string,hierarchy_id:?string,include_descendants:bool,unrestricted?:bool}>  $scopes
     */
    public function assertNoRedundantGrants(array $scopes): void
    {
        foreach ($scopes as $index => $scope) {
            foreach (array_slice($scopes, $index + 1) as $other) {
                if ($scope['policy_code'] !== $other['policy_code']) {
                    continue;
                }

                if (($scope['unrestricted'] ?? false) || ($other['unrestricted'] ?? false)) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Batas data seluruh organisasi tidak boleh digabung dengan batas data lain pada role yang sama.',
                    ]);
                }

                if ($scope['legal_entity_id'] !== $other['legal_entity_id']
                    || $scope['organization_id'] !== $other['organization_id']) {
                    continue;
                }

                $sameGrant = (! $scope['include_descendants'] && ! $other['include_descendants'])
                    || ($scope['include_descendants'] && $other['include_descendants']
                        && $scope['hierarchy_id'] === $other['hierarchy_id']);
                $directGrantCovered = $scope['include_descendants'] !== $other['include_descendants'];

                if ($sameGrant || $directGrantCovered) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Batas data yang sama atau sudah tercakup tidak boleh ditambahkan dua kali pada role yang sama.',
                    ]);
                }
            }
        }
    }

    private function legalEntityId(string $tenantId, ?string $legalEntityId, bool $required): ?string
    {
        if ($legalEntityId === null) {
            if ($required) {
                throw ValidationException::withMessages(['legal_entity_id' => 'Pilih badan hukum untuk batas akses ini.']);
            }

            return null;
        }

        $legalEntity = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('classification', 'legal_entity')
            ->find($legalEntityId);
        if (! $legalEntity) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Badan hukum tidak tersedia pada bisnis aktif.']);
        }

        return $legalEntity->id;
    }

    private function organization(string $tenantId, ?string $organizationId, bool $requiresOperatingUnit): ?Organization
    {
        if ($organizationId === null) {
            if ($requiresOperatingUnit) {
                throw ValidationException::withMessages(['organization_id' => 'Pilih unit atau node organisasi untuk batas akses ini.']);
            }

            return null;
        }

        $organization = Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->find($organizationId);
        if (! $organization) {
            throw ValidationException::withMessages(['organization_id' => 'Organisasi tidak tersedia pada bisnis aktif.']);
        }
        if ($requiresOperatingUnit && $organization->classification !== 'operating_unit') {
            throw ValidationException::withMessages(['organization_id' => 'Pilih unit kerja, bukan badan hukum, untuk batas akses ini.']);
        }

        return $organization;
    }

    private function roleUsesPolicy(string $tenantId, Role $role, AppDataPolicy $policy): bool
    {
        $roleIds = app(RoleHierarchy::class)->effectiveRoleIds($tenantId, [$role->id]);

        return DB::table('security_role_duties as role_duties')
            ->join('security_duty_privileges as duty_privileges', 'duty_privileges.duty_code', '=', 'role_duties.duty_code')
            ->join('security_privilege_permissions as privilege_permissions', 'privilege_permissions.privilege_code', '=', 'duty_privileges.privilege_code')
            ->whereIn('role_duties.role_id', $roleIds)
            ->whereIn('privilege_permissions.permission_code', $policy->protected_permissions)
            ->exists();
    }
}
