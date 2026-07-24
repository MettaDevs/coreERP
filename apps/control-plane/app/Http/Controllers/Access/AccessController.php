<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\CoreApp;
use App\Models\InvitationCode;
use App\Models\Organization;
use App\Models\OrganizationHierarchy;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessController extends Controller
{
    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        $tenantId = $membership->tenant_id;

        return Inertia::render('settings/access', [
            'canManage' => $membership->canManageAccess(),
            'tenant' => $membership->tenant->only(['id', 'name']),
            'members' => TenantMembership::query()
                ->where('tenant_id', $tenantId)
                ->with(['user:id,name,email,last_login_at', 'roleAssignments.role:id,name', 'roleAssignments.organizationScope'])
                ->orderBy('created_at')
                ->get()
                ->map(function (TenantMembership $member) use ($membership): array {
                    $assignments = $member->roleAssignments->where('status', 'active');
                    $scope = $assignments->first()?->organizationScope;

                    return [
                        'id' => $member->id,
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                        'system_role' => $member->system_role,
                        'status' => $member->status,
                        'last_login_at' => $member->user->last_login_at,
                        'roles' => $assignments->map(fn (RoleAssignment $assignment) => $assignment->role->name)->values(),
                        'role_ids' => $assignments->pluck('role_id')->values(),
                        'organization_id' => $scope?->organization_id,
                        'hierarchy_id' => $scope?->hierarchy_id,
                        'include_descendants' => $scope->include_descendants ?? false,
                        'can_edit_access' => $member->system_role !== 'owner' || $member->id === $membership->id,
                    ];
                }),
            'apps' => CoreApp::query()
                ->whereHas('duties')
                ->with('duties:code,app_id,name,description')
                ->get(['id', 'name']),
            'roles' => Role::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->with('duties:code,name,app_id')
                ->orderBy('name')
                ->get(),
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
                    'organization_id' => $invitation->organization_id,
                    'include_descendants' => $invitation->include_descendants,
                    'roles' => $invitation->roles->pluck('name'),
                    'expires_at' => $invitation->expires_at,
                    'used_at' => $invitation->used_at,
                    'revoked_at' => $invitation->revoked_at,
                ]),
            'newInvitationCode' => $request->session()->pull('new_invitation_code'),
        ]);
    }
}
