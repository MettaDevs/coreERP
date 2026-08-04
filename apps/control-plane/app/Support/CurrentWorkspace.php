<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

final class CurrentWorkspace
{
    private const MEMBERSHIP_KEY = 'workspace.membership_id';

    private const LEGAL_ENTITY_KEY = 'workspace.legal_entity_id';

    private const OPERATING_UNIT_KEY = 'workspace.org_unit_id';

    /** @return Collection<int, TenantMembership> */
    public function memberships(Request $request): Collection
    {
        $user = $request->user();
        if (! $user) {
            return new Collection;
        }

        return $user->memberships()->with('tenant')->where('status', 'active')->orderBy('created_at')->get();
    }

    public function membership(Request $request): ?TenantMembership
    {
        $memberships = $this->memberships($request);
        $membership = $memberships->firstWhere('id', $request->session()->get(self::MEMBERSHIP_KEY)) ?? $memberships->first();
        if ($membership) {
            $request->session()->put(self::MEMBERSHIP_KEY, $membership->id);
        } else {
            $request->session()->forget([self::MEMBERSHIP_KEY, self::LEGAL_ENTITY_KEY, self::OPERATING_UNIT_KEY]);
        }

        return $membership;
    }

    /** @return Collection<int, Organization> */
    public function organizations(TenantMembership $membership): Collection
    {
        $query = Organization::query()->where('tenant_id', $membership->tenant_id)->where('status', 'active')->orderBy('name');
        $policies = app(DataPolicyAccessResolver::class)->resolve($membership);
        if (collect($policies)->contains(fn (array $scope): bool => $scope['all'])) {
            return $query->get();
        }
        $organizationIds = collect($policies)
            ->flatMap(fn (array $scope): array => $scope['scope_grants'])
            ->flatMap(fn (array $grant): array => array_filter([
                $grant['legal_entity_id'],
                ...$grant['operating_unit_ids'],
            ]))
            ->unique()
            ->values();

        return $organizationIds->isEmpty() ? new Collection : $query->whereIn('id', $organizationIds)->get();
    }

    public function legalEntity(Request $request, TenantMembership $membership): ?Organization
    {
        return $this->selected($request, $membership, 'legal_entity', self::LEGAL_ENTITY_KEY);
    }

    public function operatingUnit(Request $request, TenantMembership $membership): ?Organization
    {
        return $this->selected($request, $membership, 'operating_unit', self::OPERATING_UNIT_KEY);
    }

    public function activate(Request $request, TenantMembership $membership, ?Organization $legalEntity, ?Organization $operatingUnit): void
    {
        $request->session()->put(self::MEMBERSHIP_KEY, $membership->id);
        $this->storeSelection($request, self::LEGAL_ENTITY_KEY, $legalEntity);
        $this->storeSelection($request, self::OPERATING_UNIT_KEY, $operatingUnit);
    }

    private function selected(Request $request, TenantMembership $membership, string $classification, string $key): ?Organization
    {
        $organizations = $this->organizations($membership)->where('classification', $classification);
        $selected = $organizations->firstWhere('id', $request->session()->get($key)) ?? $organizations->first();
        $this->storeSelection($request, $key, $selected);

        return $selected;
    }

    private function storeSelection(Request $request, string $key, ?Organization $organization): void
    {
        $organization ? $request->session()->put($key, $organization->id) : $request->session()->forget($key);
    }
}
