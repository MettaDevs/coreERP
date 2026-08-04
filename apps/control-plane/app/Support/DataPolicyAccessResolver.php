<?php

namespace App\Support;

use App\Models\TenantMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DataPolicyAccessResolver
{
    /**
     * @return array<string, array{all:bool,scope_grants:list<array{legal_entity_id:?string,operating_unit_ids:list<string>}>}>
     */
    public function resolve(TenantMembership $membership): array
    {
        $now = now();
        $scopes = DB::table('role_assignment_data_policy_scopes as scope')
            ->join('role_assignments as assignment', 'assignment.id', '=', 'scope.role_assignment_id')
            ->where('scope.tenant_id', $membership->tenant_id)
            ->where('assignment.membership_id', $membership->id)
            ->where('assignment.status', 'active')
            ->where('assignment.valid_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('assignment.valid_until')->orWhere('assignment.valid_until', '>', $now))
            ->where('scope.valid_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('scope.valid_until')->orWhere('scope.valid_until', '>', $now))
            ->get([
                'scope.policy_code', 'scope.legal_entity_id', 'scope.organization_id',
                'scope.hierarchy_version_id', 'scope.include_descendants',
            ]);

        return $scopes
            ->groupBy('policy_code')
            ->map(fn (Collection $policyScopes): array => $this->resolvePolicy($membership->tenant_id, $policyScopes))
            ->all();
    }

    /**
     * @param  Collection<int, object>  $scopes
     * @return array{all:bool,scope_grants:list<array{legal_entity_id:?string,operating_unit_ids:list<string>}>}
     */
    private function resolvePolicy(string $tenantId, Collection $scopes): array
    {
        if ($scopes->contains(fn (object $scope): bool => $scope->legal_entity_id === null && $scope->organization_id === null)) {
            return ['all' => true, 'scope_grants' => []];
        }

        return [
            'all' => false,
            'scope_grants' => $scopes
                ->map(fn (object $scope): array => $this->resolveGrant($tenantId, $scope))
                ->filter(fn (array $grant): bool => $grant['legal_entity_id'] !== null || $grant['operating_unit_ids'] !== [])
                ->values()
                ->all(),
        ];
    }

    /** @return array{legal_entity_id:?string,operating_unit_ids:list<string>} */
    private function resolveGrant(string $tenantId, object $scope): array
    {
        $organizationIds = collect([$scope->organization_id])->filter();
        if ($scope->include_descendants) {
            $organizationIds = $organizationIds->merge(DB::table('organization_hierarchy_closures')
                ->where('version_id', $scope->hierarchy_version_id)
                ->where('ancestor_organization_id', $scope->organization_id)
                ->pluck('descendant_organization_id'));
        }

        return [
            'legal_entity_id' => $scope->legal_entity_id,
            'operating_unit_ids' => DB::table('organizations')
                ->where('tenant_id', $tenantId)
                ->where('classification', 'operating_unit')
                ->whereIn('id', $organizationIds->unique())
                ->pluck('id')
                ->values()
                ->all(),
        ];
    }
}
