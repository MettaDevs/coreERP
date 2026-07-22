<?php

namespace App\Http\Middleware;

use App\Models\TenantMembership;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $workspace = app(CurrentWorkspace::class);
        $memberships = $workspace->memberships($request);
        $membership = $workspace->membership($request);
        $organizations = $membership ? $workspace->organizations($membership) : collect();
        $legalEntity = $membership ? $workspace->legalEntity($request, $membership) : null;
        $orgUnit = $membership ? $workspace->operatingUnit($request, $membership) : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'membership' => $membership ? [
                    'id' => $membership->id,
                    'system_role' => $membership->system_role,
                    'tenant_id' => $membership->tenant_id,
                    'tenant_name' => $membership->tenant->name,
                ] : null,
                'provider_admin' => $user?->providerAccess()->where('role', 'provider_admin')->exists() ?? false,
            ],
            'workspace' => [
                'memberships' => $memberships->map(fn (TenantMembership $item) => [
                    'id' => $item->id,
                    'tenant_id' => $item->tenant_id,
                    'tenant_name' => $item->tenant->name,
                    'system_role' => $item->system_role,
                ])->values(),
                'active_legal_entity' => $legalEntity ? [
                    'id' => $legalEntity->id,
                    'name' => $legalEntity->name,
                    'classification' => $legalEntity->classification,
                ] : null,
                'active_org_unit' => $orgUnit ? [
                    'id' => $orgUnit->id,
                    'name' => $orgUnit->name,
                    'classification' => $orgUnit->classification,
                ] : null,
                'legal_entities' => $organizations->where('classification', 'legal_entity')->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'classification' => $item->classification,
                ])->values(),
                'org_units' => $organizations->where('classification', 'operating_unit')->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'classification' => $item->classification,
                ])->values(),
            ],
            'entitledProducts' => fn (): array => $this->entitledProducts($membership),
            'launchableProducts' => fn (): array => $this->launchableProducts($membership),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return list<array{id:string,name:string,description:string,href:string}>
     */
    private function entitledProducts(?TenantMembership $membership): array
    {
        if (! $membership) {
            return [];
        }

        $entitledIds = $membership->tenant->entitlements()
            ->where('status', 'active')
            ->whereRaw('(ends_at is null or ends_at > ?)', [now()])
            ->pluck('module_id');
        /** @var array<int, array<string, mixed>> $catalog */
        $catalog = $this->moduleCatalog();

        return array_values(collect($catalog)
            ->filter(fn (array $module): bool => $entitledIds->contains($module['id'] ?? null))
            ->map(fn (array $module): array => [
                'id' => (string) $module['id'],
                'name' => (string) $module['name'],
                'description' => (string) ($module['description'] ?? ''),
                'href' => (string) $module['ui_entry'],
            ])
            ->values()
            ->all());
    }

    /** @return list<array{id:string,name:string,description:string,href:string}> */
    private function launchableProducts(?TenantMembership $membership): array
    {
        if (! $membership) {
            return [];
        }

        $authorizedModuleIds = DB::table('role_assignments as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->join('security_role_duties as role_duties', 'role_duties.role_id', '=', 'assignments.role_id')
            ->join('security_duty_privileges as duty_privileges', 'duty_privileges.duty_code', '=', 'role_duties.duty_code')
            ->join('security_privilege_permissions as privilege_permissions', 'privilege_permissions.privilege_code', '=', 'duty_privileges.privilege_code')
            ->join('permissions', 'permissions.code', '=', 'privilege_permissions.permission_code')
            ->where('assignments.membership_id', $membership->id)
            ->where('assignments.status', 'active')
            ->where('roles.is_active', true)
            ->where('assignments.valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('assignments.valid_until')->orWhere('assignments.valid_until', '>', now()))
            ->distinct()
            ->pluck('permissions.module_id');

        $readyIds = $membership->tenant->entitlements()
            ->where('status', 'active')
            ->whereIn('module_id', $authorizedModuleIds)
            ->whereRaw('(ends_at is null or ends_at > ?)', [now()])
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('tenant_deployments')
                ->join('module_placements', 'module_placements.placement', '=', 'tenant_deployments.placement')
                ->whereColumn('tenant_deployments.tenant_id', 'tenant_module_entitlements.tenant_id')
                ->whereColumn('module_placements.module_id', 'tenant_module_entitlements.module_id')
                ->whereColumn('module_placements.profile', 'tenant_deployments.profile')
                ->where('tenant_deployments.status', 'active')
                ->where('module_placements.artifact_status', 'placed')
                ->where('module_placements.migration_status', 'succeeded')
                ->where('module_placements.runtime_status', 'ready')
                ->whereNotNull('module_placements.ready_at'))
            ->pluck('module_id');
        $catalog = $this->moduleCatalog();

        return array_values(collect($catalog)
            ->filter(fn (array $module): bool => $readyIds->contains($module['id'] ?? null))
            ->map(fn (array $module): array => [
                'id' => (string) $module['id'],
                'name' => (string) $module['name'],
                'description' => (string) ($module['description'] ?? ''),
                'href' => (string) $module['ui_entry'],
            ])->values()->all());
    }

    /** @return list<array<string, mixed>> */
    private function moduleCatalog(): array
    {
        $configured = config('coreerp.module_catalog');
        if (! is_array($configured)) {
            return [];
        }

        $catalog = [];
        foreach ($configured as $module) {
            if (is_array($module)) {
                $catalog[] = $module;
            }
        }

        return $catalog;
    }
}
