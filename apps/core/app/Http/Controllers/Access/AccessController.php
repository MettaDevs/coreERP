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
use App\Models\TenantMembership;
use App\Support\RoleHierarchy;
use App\Support\Sso\TenantSso;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
                                'unrestricted' => $scope->legal_entity_id === null && $scope->organization_id === null,
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
                ->where('organizations.tenant_id', $tenantId)
                ->where('organizations.status', 'active')
                ->leftJoin('operating_units', 'operating_units.organization_id', '=', 'organizations.id')
                ->orderBy('organizations.name')
                ->get(['organizations.id', 'organizations.name', 'organizations.classification', 'operating_units.type as unit_type']),
            'hierarchies' => $this->hierarchies($tenantId),
            'invitations' => $this->invitations($tenantId, $membership->canManageAccess()),
            'newInvitationCodes' => $request->session()->pull('new_invitation_codes', []),
            // Kolom "Diundang" hanya berarti bila tenant ini memang memakai SSO; tanpa itu, yang
            // muncul adalah kotak email yang setiap isinya pasti ditolak.
            'ssoAvailable' => app(TenantSso::class)->availableFor($tenantId),
        ]);
    }

    /**
     * Assignment ikut dikirim supaya grid kode undangan dapat menampilkan
     * undangan yang sudah ada dengan kolom yang sama seperti baris baru —
     * tanggung jawab dan batas datanya terbaca, bukan sekadar nama role.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function invitations(string $tenantId, bool $canManage): Collection
    {
        $scopes = DB::table('invitation_data_policy_scopes as scope')
            ->join('invitation_codes as invitation', 'invitation.id', '=', 'scope.invitation_id')
            ->where('invitation.tenant_id', $tenantId)
            ->get([
                'scope.invitation_id', 'scope.role_id', 'scope.policy_code',
                'scope.legal_entity_id', 'scope.organization_id',
                'scope.hierarchy_id', 'scope.include_descendants',
            ])
            ->groupBy('invitation_id');
        // Redemption menandai assignment dengan `invitation:<id>`, jadi jumlah
        // pemakai terbaca tanpa tabel tambahan. Angka ini dipakai UI untuk
        // memperingatkan bahwa kode sudah beredar sebelum diubah.
        $redeemed = DB::table('role_assignments')
            ->join('tenant_memberships as membership', 'membership.id', '=', 'role_assignments.membership_id')
            ->where('membership.tenant_id', $tenantId)
            ->whereNotNull('role_assignments.source_reference')
            ->select('role_assignments.source_reference', 'role_assignments.membership_id')
            ->distinct()
            ->get()
            ->groupBy('source_reference')
            ->map(fn (Collection $rows): int => $rows->pluck('membership_id')->unique()->count());

        return InvitationCode::query()
            ->where('tenant_id', $tenantId)
            ->with(['roles:id,name'])
            ->latest()
            ->get()
            ->map(function (InvitationCode $invitation) use ($scopes, $redeemed, $canManage): array {
                $byRole = $scopes->get($invitation->id, collect())->groupBy('role_id');

                return [
                    'id' => $invitation->id,
                    'redeemed_count' => $redeemed->get('invitation:'.$invitation->id, 0),
                    'system_role' => $invitation->system_role,
                    'label' => $invitation->label,
                    'roles' => $invitation->roles->pluck('name')->values(),
                    'assignments' => $invitation->roles->map(fn (Role $role): array => [
                        'role_id' => $role->id,
                        'role_name' => $role->name,
                        'policy_scopes' => $byRole->get($role->id, collect())
                            ->map(fn (object $scope): array => [
                                'policy_code' => $scope->policy_code,
                                'legal_entity_id' => $scope->legal_entity_id,
                                'organization_id' => $scope->organization_id,
                                'hierarchy_id' => $scope->hierarchy_id,
                                'include_descendants' => (bool) $scope->include_descendants,
                                'unrestricted' => $scope->legal_entity_id === null && $scope->organization_id === null,
                            ])
                            ->values(),
                    ])->values(),
                    'code' => $canManage ? $invitation->accessibleCode() : null,
                    'expires_at' => $invitation->expires_at,
                    'revoked_at' => $invitation->revoked_at,
                    // Kosong untuk kode anonim. Isinya hanya untuk ditampilkan: yang menentukan
                    // siapa boleh menukarkannya adalah subjek, dan subjek tidak pernah ke layar.
                    'sso' => $invitation->isSsoBound() ? [
                        'email' => $invitation->sso_email_at_invite,
                        'name' => $invitation->sso_name_at_invite,
                        'notified_at' => $invitation->sso_notified_at,
                        'redeemed_at' => $invitation->sso_redeemed_at,
                    ] : null,
                ];
            });
    }

    /**
     * Node susunan organisasi ikut dikirim agar layar batas data dapat
     * menampilkan pohon sesuai susunan yang dipilih, bukan daftar unit datar.
     * Yang dipakai hanya versi published yang sedang berlaku — versi itu juga
     * yang nanti dicatat pada grant.
     *
     * @return Collection<int, array{id:string,name:string,version_id:?string,nodes:array<int, array{organization_id:string,parent_organization_id:?string}>}>
     */
    private function hierarchies(string $tenantId): Collection
    {
        return OrganizationHierarchy::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['versions' => fn ($query) => $query
                ->where('status', 'published')
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->orderByDesc('version_number')])
            ->orderBy('name')
            ->get()
            ->map(function (OrganizationHierarchy $hierarchy): array {
                $version = $hierarchy->versions->first();

                return [
                    'id' => $hierarchy->id,
                    'name' => $hierarchy->name,
                    'version_id' => $version?->id,
                    'nodes' => $version === null ? [] : DB::table('organization_hierarchy_nodes as node')
                        ->leftJoin('organization_hierarchy_nodes as parent', 'parent.id', '=', 'node.parent_node_id')
                        ->where('node.version_id', $version->id)
                        ->get(['node.organization_id', 'parent.organization_id as parent_organization_id'])
                        ->map(fn (object $node): array => [
                            'organization_id' => (string) $node->organization_id,
                            'parent_organization_id' => $node->parent_organization_id === null
                                ? null
                                : (string) $node->parent_organization_id,
                        ])
                        ->values()
                        ->all(),
                ];
            });
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
