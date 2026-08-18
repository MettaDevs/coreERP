<?php

namespace App\Support;

use App\Models\CoreApp;
use App\Models\TenantMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LaunchableAppCatalog
{
    /** @return list<string> */
    public function permissionsFor(TenantMembership $membership, string $appId): array
    {
        return $this->permissionQuery($membership)
            ->where('permissions.app_id', $appId)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->all();
    }

    /**
     * Entry UI diturunkan dari placement yang melayani tenant ini, bukan dibaca
     * dari kolom. Placement adalah unit silo/pool, jadi dua tenant pada
     * placement berbeda memperoleh path berbeda tanpa nilai apa pun disimpan.
     */
    public function runtimeFor(TenantMembership $membership, string $appId): ?string
    {
        $placement = $this->readyPlacementQuery($membership)
            ->where('placements.app_id', $appId)
            ->value('placements.placement');

        return $placement === null ? null : AppContentPath::for($appId, (string) $placement);
    }

    /**
     * @return list<array{id:string,label:string,href:string,items:list<array{id:string,label:string,href:string}>}>
     */
    public function navigationFor(TenantMembership $membership, CoreApp $app): array
    {
        $allowed = array_flip($this->permissionsFor($membership, $app->id));
        $navigation = $app->navigation ?? [];
        $sidebar = is_array($navigation['sidebar'] ?? null) ? $navigation['sidebar'] : [];

        return array_values(collect($navigation['rail'] ?? [])->map(function (array $rail) use ($allowed, $app, $sidebar): ?array {
            $items = array_values(collect($sidebar[$rail['id']] ?? [])
                ->filter(fn (array $item): bool => isset($allowed[$item['permission']]))
                ->map(fn (array $item): array => [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'href' => '/apps/'.$app->id.'?view='.rawurlencode($item['id']),
                ])->all());

            return $items === [] ? null : [
                'id' => $rail['id'],
                'label' => $rail['label'],
                'href' => $items[0]['href'],
                'items' => $items,
            ];
        })->filter()->all());
    }

    /** @return list<array{id:string,name:string,description:string,href:string,version:string}> */
    public function for(TenantMembership $membership): array
    {
        $authorizedAppIds = $this->permissionQuery($membership)
            ->distinct()
            ->pluck('permissions.app_id');

        $readyAppIds = $this->readyPlacementQuery($membership)
            ->whereIn('placements.app_id', $authorizedAppIds)
            ->distinct()
            ->pluck('placements.app_id');

        return array_values(
            CoreApp::query()
                ->whereIn('id', $readyAppIds)
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'version'])
                ->map(fn (CoreApp $app): array => [
                    'id' => $app->id,
                    'name' => $app->name,
                    'description' => $app->description ?? '',
                    'href' => '/apps/'.$app->id,
                    'version' => $app->version,
                ])->all(),
        );
    }

    private function permissionQuery(TenantMembership $membership): Builder
    {
        // Rantai kanoniknya adalah role -> duty -> privilege -> permission.
        // Role yang diberikan kepada user diperluas lebih dahulu melalui hierarchy
        // role, karena parent mewarisi duty seluruh turunannya.
        $assignedRoleIds = DB::table('role_assignments as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.membership_id', $membership->id)
            ->where('assignments.status', 'active')
            ->where('roles.is_active', true)
            ->where('assignments.valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('assignments.valid_until')->orWhere('assignments.valid_until', '>', now()))
            ->pluck('assignments.role_id')
            ->all();

        $effectiveRoleIds = app(RoleHierarchy::class)
            ->effectiveRoleIds($membership->tenant_id, array_map(strval(...), $assignedRoleIds));

        return DB::table('security_role_duties as role_duties')
            ->join('security_duty_privileges as duty_privileges', 'duty_privileges.duty_code', '=', 'role_duties.duty_code')
            ->join('security_privilege_permissions as privilege_permissions', 'privilege_permissions.privilege_code', '=', 'duty_privileges.privilege_code')
            ->join('permissions', 'permissions.code', '=', 'privilege_permissions.permission_code')
            ->whereIn('role_duties.role_id', $effectiveRoleIds);
    }

    private function readyPlacementQuery(TenantMembership $membership): Builder
    {
        return DB::table('tenant_app_entitlements as entitlements')
            ->join('tenant_deployments as deployments', 'deployments.tenant_id', '=', 'entitlements.tenant_id')
            ->join('app_placements as placements', 'placements.placement', '=', 'deployments.placement')
            ->join('app_releases as releases', function ($join) {
                $join->on('releases.app_id', '=', 'placements.app_id')
                    ->on('releases.version', '=', 'placements.release_version')
                    ->where('releases.status', 'available');
            })
            ->where('entitlements.tenant_id', $membership->tenant_id)
            ->where('entitlements.status', 'active')
            ->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->where('deployments.status', 'active')
            ->whereColumn('placements.app_id', 'entitlements.app_id')
            ->whereColumn('placements.profile', 'deployments.profile')
            ->where('placements.artifact_status', 'placed')
            ->where('placements.migration_status', 'succeeded')
            ->where('placements.runtime_status', 'ready')
            ->whereNotNull('placements.ready_at');
    }
}
