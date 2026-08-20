<?php

namespace App\Actions\NumberSequence;

use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use Illuminate\Support\Facades\DB;

class EnsureNumberSequenceDrafts
{
    public function forReadyApp(string $appId): void
    {
        $tenantIds = DB::table('tenant_deployments as deployments')
            ->join('tenant_app_entitlements as entitlements', 'entitlements.tenant_id', '=', 'deployments.tenant_id')
            ->join('app_placements as placements', 'placements.placement', '=', 'deployments.placement')
            ->where('entitlements.app_id', $appId)
            ->where('entitlements.status', 'active')
            ->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->where('deployments.status', 'active')
            ->whereColumn('placements.profile', 'deployments.profile')
            ->where('placements.app_id', $appId)
            ->where('placements.artifact_status', 'placed')
            ->where('placements.migration_status', 'succeeded')
            ->where('placements.runtime_status', 'ready')
            ->whereNotNull('placements.ready_at')
            ->pluck('deployments.tenant_id');

        foreach ($tenantIds->unique() as $tenantId) {
            $this->forTenantAndApp((string) $tenantId, $appId);
        }
    }

    /**
     * The single-tenant counterpart of forReadyApp, applying exactly the same readiness rules.
     *
     * forReadyApp walks every entitled tenant, which is correct when an app becomes ready but ruinous on a page load:
     * at a thousand tenants it costs thousands of round-trips per request, on behalf of tenants that are not even
     * looking. Anything request-scoped must use this instead.
     */
    public function forReadyTenant(string $tenantId): void
    {
        $appIds = DB::table('tenant_deployments as deployments')
            ->join('tenant_app_entitlements as entitlements', 'entitlements.tenant_id', '=', 'deployments.tenant_id')
            ->join('app_placements as placements', 'placements.placement', '=', 'deployments.placement')
            ->where('deployments.tenant_id', $tenantId)
            ->whereColumn('placements.app_id', 'entitlements.app_id')
            ->where('entitlements.status', 'active')
            ->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->where('deployments.status', 'active')
            ->whereColumn('placements.profile', 'deployments.profile')
            ->where('placements.artifact_status', 'placed')
            ->where('placements.migration_status', 'succeeded')
            ->where('placements.runtime_status', 'ready')
            ->whereNotNull('placements.ready_at')
            ->pluck('entitlements.app_id');

        foreach ($appIds->unique() as $appId) {
            $this->forTenantAndApp($tenantId, (string) $appId);
        }
    }

    public function forTenantAndApp(string $tenantId, string $appId): void
    {
        $profile = DB::table('number_sequence_profiles')->where('code', 'non-continuous-default')->first();
        if (! $profile) {
            return;
        }

        NumberSequenceReference::query()->where('app_id', $appId)->each(function (NumberSequenceReference $reference) use ($tenantId, $profile): void {
            TenantNumberSequence::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'reference_id' => $reference->id],
                [
                    'profile_code' => $profile->code,
                    'scope_type' => $this->defaultScope($reference),
                    'status' => 'active',
                    'is_continuous' => (bool) $profile->is_continuous,
                    'allow_manual' => (bool) $profile->allow_manual,
                    'preallocation_enabled' => (bool) $profile->preallocation_enabled,
                    'preallocation_quantity' => (int) $profile->preallocation_quantity,
                    'minimum_number' => 0,
                    'maximum_number' => 19999,
                    'segments' => array_values(array_filter([
                        $reference->default_prefix ? ['type' => 'constant', 'value' => $reference->default_prefix] : null,
                        ['type' => 'number', 'length' => 5],
                    ])),
                ],
            );
        });
    }

    private function defaultScope(NumberSequenceReference $reference): string
    {
        // Tenant is the least specific scope and remains the default whenever
        // the manifest permits it. Transaction references that only allow a
        // legal entity must materialize that narrower scope from the start.
        foreach (['tenant', 'legal_entity', 'operating_unit'] as $scope) {
            if (in_array($scope, $reference->allowed_scopes, true)) {
                return $scope;
            }
        }

        throw new \LogicException('Reference number sequence tidak memiliki scope yang valid: '.$reference->code);
    }
}
