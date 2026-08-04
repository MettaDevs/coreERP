<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\AppDataPolicy;
use App\Models\CoreApp;
use App\Models\InvitationCode;
use App\Models\Organization;
use App\Models\OrganizationHierarchy;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\SecurityDuty;
use App\Models\TenantMembership;
use App\Support\RoleHierarchy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessController extends Controller
{
    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        $tenantId = $membership->tenant_id;
        $entitledAppIds = $membership->tenant->entitlements()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('app_id');
        return Inertia::render('settings/access', [
            'canManage' => $membership->canManageAccess(),
            'tenant' => $membership->tenant->only(['id', 'name']),
            'members' => TenantMembership::query()
                ->where('tenant_id', $tenantId)
                ->with(['user:id,name,email,last_login_at', 'roleAssignments.role:id,name', 'roleAssignments.dataPolicyScopes.policy'])
                ->orderBy('created_at')
                ->get()
                ->map(function (TenantMembership $member) use ($membership): array {
                    $assignments = $member->roleAssignments->where('status', 'active');

                    return [
                        'id' => $member->id,
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                        'system_role' => $member->system_role,
                        'status' => $member->status,
                        'last_login_at' => $member->user->last_login_at,
                        'roles' => $assignments->map(fn (RoleAssignment $assignment) => $assignment->role->name)->values(),
                        'role_ids' => $assignments->pluck('role_id')->values(),
                        'assignments' => $assignments->map(fn (RoleAssignment $assignment) => [
                            'role_id' => $assignment->role_id,
                            'role_name' => $assignment->role->name,
                            'source' => $assignment->source,
                            'policy_scopes' => $assignment->dataPolicyScopes->map(fn ($scope) => [
                                'policy_code' => $scope->policy_code,
                                'policy_name' => $scope->policy?->name,
                                'legal_entity_id' => $scope->legal_entity_id,
                                'organization_id' => $scope->organization_id,
                                'hierarchy_id' => $scope->hierarchy_id,
                                'include_descendants' => $scope->include_descendants,
                                'valid_from' => $scope->valid_from?->toDateTimeString(),
                                'valid_until' => $scope->valid_until?->toDateTimeString(),
                            ])->values(),
                        ])->values(),
                        'can_edit_access' => $member->system_role !== 'owner' || $member->id === $membership->id,
                    ];
                }),
            'apps' => CoreApp::query()
                ->whereIn('id', $entitledAppIds)
                ->whereHas('duties')
                ->with(['duties.privileges.permissions'])
                ->get(['id', 'name']),
            'customDuties' => SecurityDuty::query()
                ->where('tenant_id', $tenantId)
                ->where('source', 'custom')
                ->where('status', 'active')
                ->with(['privileges.permissions'])
                ->orderBy('name')->get(['code', 'app_id', 'name']),
            'roles' => $this->roles($tenantId),
            'dataPolicies' => AppDataPolicy::query()
                ->whereIn('app_id', $entitledAppIds)
                ->orderBy('code')
                ->get()
                ->map(fn (AppDataPolicy $policy) => [
                    'code' => $policy->code,
                    'name' => $policy->name,
                    'requires_legal_entity' => $policy->requires_legal_entity,
                    'requires_operating_unit' => $policy->requires_operating_unit,
                    'allows_descendants' => $policy->allows_descendants,
                ])
                ->values(),
            'organizations' => Organization::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'classification']),
            'hierarchies' => OrganizationHierarchy::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'invitations' => InvitationCode::query()
                ->where('tenant_id', $tenantId)
                ->with(['roles:id,name'])
                ->latest()
                ->get()
                ->map(fn (InvitationCode $invitation) => [
                    'id' => $invitation->id,
                    'system_role' => $invitation->system_role,
                    'roles' => $invitation->roles->pluck('name'),
                    'code' => $membership->canManageAccess() ? $invitation->accessibleCode() : null,
                    'expires_at' => $invitation->expires_at,
                    'revoked_at' => $invitation->revoked_at,
                ]),
            'newInvitationCode' => $request->session()->pull('new_invitation_code'),
        ]);
    }

    /** @return Collection<int, array{id:string,name:string,duties:Collection<int, mixed>,data_policy_codes:list<string>}> */
    private function roles(string $tenantId): Collection
    {
        $policies = AppDataPolicy::query()->get(['code', 'protected_permissions']);

        return Role::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('duties:code,name,app_id')
            ->orderBy('name')
            ->get()
            ->map(function (Role $role) use ($tenantId, $policies): array {
                $roleIds = app(RoleHierarchy::class)->effectiveRoleIds($tenantId, [$role->id]);
                $permissionCodes = DB::table('security_role_duties as role_duties')
                    ->join('security_duty_privileges as duty_privileges', 'duty_privileges.duty_code', '=', 'role_duties.duty_code')
                    ->join('security_privilege_permissions as privilege_permissions', 'privilege_permissions.privilege_code', '=', 'duty_privileges.privilege_code')
                    ->whereIn('role_duties.role_id', $roleIds)
                    ->pluck('privilege_permissions.permission_code')
                    ->unique()
                    ->all();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'duties' => $role->duties->map(fn ($duty) => $duty->only(['code', 'app_id', 'name']))->values(),
                    'data_policy_codes' => $policies
                        ->filter(fn (AppDataPolicy $policy): bool => array_intersect($policy->protected_permissions, $permissionCodes) !== [])
                        ->pluck('code')
                        ->values()
                        ->all(),
                ];
            });
    }
}
