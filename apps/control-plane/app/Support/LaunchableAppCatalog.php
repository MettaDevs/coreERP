<?php

namespace App\Support;

use App\Models\CoreApp;
use App\Models\TenantMembership;
use Illuminate\Support\Facades\DB;

class LaunchableAppCatalog
{
    /** @return list<array{id:string,name:string,description:string,href:string,version:string}> */
    public function for(TenantMembership $membership): array
    {
        $authorizedAppIds = DB::table('role_assignments as assignments')
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
            ->pluck('permissions.app_id');

        $readyAppIds = $membership->tenant->entitlements()
            ->where('status', 'active')
            ->whereIn('app_id', $authorizedAppIds)
            ->whereRaw('(ends_at is null or ends_at > ?)', [now()])
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('tenant_deployments')
                ->join('app_placements', 'app_placements.placement', '=', 'tenant_deployments.placement')
                ->whereColumn('tenant_deployments.tenant_id', 'tenant_app_entitlements.tenant_id')
                ->whereColumn('app_placements.app_id', 'tenant_app_entitlements.app_id')
                ->whereColumn('app_placements.profile', 'tenant_deployments.profile')
                ->where('tenant_deployments.status', 'active')
                ->where('app_placements.artifact_status', 'placed')
                ->where('app_placements.migration_status', 'succeeded')
                ->where('app_placements.runtime_status', 'ready')
                ->whereNotNull('app_placements.ready_at'))
            ->pluck('app_id');

        return CoreApp::query()
            ->whereIn('id', $readyAppIds)
            ->whereNotNull('ui_entry')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'ui_entry', 'version'])
            ->map(fn (CoreApp $app): array => [
                'id' => $app->id,
                'name' => $app->name,
                'description' => $app->description ?? '',
                'href' => '/apps/'.$app->id,
                'version' => $app->version,
            ])->all();
    }
}
