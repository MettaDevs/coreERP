<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\AppDataPolicy;
use App\Models\InvitationCode;
use App\Models\Organization;
use App\Models\OrganizationHierarchy;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
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
            /*
             * Katalog izin dikirim **ditunda**, dan kolomnya dipilih satu per satu.
             *
             * Ia hanya dibaca di dalam dialog rincian role — tidak ada satu piksel pun
             * dari halaman pertama yang membutuhkannya. Dikirim eager, ia menahan cat
             * pertama demi layar yang mungkin tidak pernah dibuka.
             *
             * Bentuk sebelumnya `->with(['duties.privileges.permissions'])->get(['id','name'])`
             * memulangkan **baris model utuh** untuk setiap simpul pohon — `created_at`,
             * `updated_at`, `tenant_id`, `source`, `status`, `published_at` ikut ke peramban
             * padahal tidak satu pun dibaca UI. Untuk dua module hasilnya 109 KB; ia tumbuh
             * mengikuti jumlah module, bukan jumlah data tenant, jadi ia memburuk pada setiap
             * module baru yang dijual.
             */
            'apps' => Inertia::defer(fn (): array => $this->katalogIzin(self::daftarString($entitledAppIds))),
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
            /*
             * Sama seperti katalog izin: pohon susunan organisasi hanya dipakai pemilih batas
             * data di dalam dialog. Ditunda, bukan dihapus — yang membukanya tetap mendapatkannya.
             */
            'hierarchies' => Inertia::defer(fn (): array => $this->hierarchies($tenantId)),
            'invitations' => $this->invitations($tenantId, $membership->canManageAccess()),
            'newInvitationCodes' => $request->session()->pull('new_invitation_codes', []),
            // Kolom "Diundang" hanya berarti bila tenant ini memang memakai SSO; tanpa itu, yang
            // muncul adalah kotak email yang setiap isinya pasti ditolak.
            'ssoAvailable' => app(TenantSso::class)->availableFor($tenantId),
        ]);
    }

    /**
     * Pohon izin per app: duty -> privilege -> permission, dengan kolom yang benar-benar
     * dibaca layar dan tidak satu pun selain itu.
     *
     * Ia disusun dari satu query datar, bukan dari `with()` bertingkat. Bedanya bukan gaya:
     * relasi bertingkat memulangkan objek model, dan objek model diserialisasi **utuh** oleh
     * Inertia — termasuk stempel waktu, `tenant_id`, `source`, `status`, dan `published_at`
     * yang tidak pernah dibaca siapa pun di peramban.
     *
     * @param  list<string>  $appIds
     * @return list<array{id:string,name:string,duties:list<array<string,mixed>>}>
     */
    private function katalogIzin(array $appIds): array
    {
        if ($appIds === []) {
            return [];
        }

        $baris = DB::table('security_duties as duty')
            ->join('apps as app', 'app.id', '=', 'duty.app_id')
            ->join('security_duty_privileges as jembatanPrivilege', 'jembatanPrivilege.duty_code', '=', 'duty.code')
            ->join('security_privileges as privilege', 'privilege.code', '=', 'jembatanPrivilege.privilege_code')
            ->leftJoin('security_privilege_permissions as jembatanPermission', 'jembatanPermission.privilege_code', '=', 'privilege.code')
            ->leftJoin('permissions as permission', 'permission.code', '=', 'jembatanPermission.permission_code')
            ->whereIn('duty.app_id', $appIds)
            ->orderBy('app.name')
            ->orderBy('duty.code')
            ->orderBy('privilege.code')
            ->orderBy('permission.code')
            ->get([
                'app.id as app_id',
                'app.name as app_name',
                'duty.code as duty_code',
                'duty.name as duty_name',
                'privilege.code as privilege_code',
                'privilege.name as privilege_name',
                'permission.code as permission_code',
                'permission.name as permission_name',
                'permission.access_level as permission_access_level',
            ]);

        /** @var array<string, string> $namaApp */
        $namaApp = [];
        /** @var array<string, array<string, array{code:string,app_id:string,name:string}>> $dutyPerApp */
        $dutyPerApp = [];
        /** @var array<string, array<string, array{code:string,name:string}>> $privilegePerDuty */
        $privilegePerDuty = [];
        /** @var array<string, array<string, array{code:string,name:string,access_level:string}>> $permissionPerPrivilege */
        $permissionPerPrivilege = [];

        foreach ($baris as $satu) {
            $appId = (string) $satu->app_id;
            $dutyCode = (string) $satu->duty_code;
            $privilegeCode = (string) $satu->privilege_code;

            $namaApp[$appId] = (string) $satu->app_name;
            $dutyPerApp[$appId][$dutyCode] = [
                'code' => $dutyCode,
                'app_id' => $appId,
                'name' => (string) $satu->duty_name,
            ];
            $privilegePerDuty[$dutyCode][$privilegeCode] = [
                'code' => $privilegeCode,
                'name' => (string) $satu->privilege_name,
            ];

            // `leftJoin` memulangkan privilege tanpa permission sebagai satu baris ber-null.
            // Barisnya tetap dibutuhkan — privilegenya nyata — tetapi permission-nya tidak ada.
            if ($satu->permission_code !== null) {
                $permissionPerPrivilege[$privilegeCode][(string) $satu->permission_code] = [
                    'code' => (string) $satu->permission_code,
                    'name' => (string) $satu->permission_name,
                    'access_level' => (string) $satu->permission_access_level,
                ];
            }
        }

        $hasil = [];

        foreach ($namaApp as $appId => $nama) {
            $duties = [];

            foreach ($dutyPerApp[$appId] ?? [] as $dutyCode => $duty) {
                $privileges = [];

                foreach ($privilegePerDuty[$dutyCode] ?? [] as $privilegeCode => $privilege) {
                    $privileges[] = [
                        ...$privilege,
                        'permissions' => array_values($permissionPerPrivilege[$privilegeCode] ?? []),
                    ];
                }

                $duties[] = [...$duty, 'privileges' => $privileges];
            }

            $hasil[] = ['id' => $appId, 'name' => $nama, 'duties' => $duties];
        }

        return $hasil;
    }

    /**
     * Isi sebuah collection sebagai daftar string yang benar-benar berurut dari nol.
     *
     * `pluck()->all()` memulangkan `array<mixed>`, dan itu bukan sekadar soal anotasi: kunci
     * collection tidak dijamin berurut, sehingga hasilnya bisa bukan list — bentuk yang membuat
     * placeholder `?` pada query di bawah tidak lagi sejajar dengan nilainya.
     *
     * @param  Collection<int|string, mixed>  $nilai
     * @return list<string>
     */
    private static function daftarString(Collection $nilai): array
    {
        return array_values(array_map(static fn (mixed $satu): string => (string) $satu, $nilai->all()));
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
     * Memulangkan daftar, bukan `Collection`: nilainya berakhir sebagai JSON, dan `TValue` pada
     * `Collection` tidak kovarian — sebuah closure yang memulangkannya tidak dapat diberi tipe
     * tanpa membuat PHPStan menuntut kesamaan yang tidak pernah ia akui.
     *
     * @return list<array{id:string,name:string,version_id:?string,nodes:array<int, array{organization_id:string,parent_organization_id:?string}>}>
     */
    private function hierarchies(string $tenantId): array
    {
        $hierarchies = OrganizationHierarchy::query()
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
            })
            ->all();

        return array_values($hierarchies);
    }

    /**
     * @return Collection<int, array{id:string,name:string,duties:Collection<int, mixed>,data_policy_codes:list<string>}>
     *
     * Dua query tetap, bukan dua query **per role**.
     *
     * Bentuk sebelumnya memanggil `RoleHierarchy::effectiveRoleIds()` — sebuah CTE rekursif —
     * lalu satu join tiga tabel, **di dalam perulangan role**. Dengan tiga role di mesin
     * pengembangan biayanya tidak terlihat; sebuah tenant dengan lima puluh role membayar
     * seratus query untuk satu kali membuka halaman, dan tidak ada satu pun yang berubah di
     * kode ketika itu terjadi. Yang tumbuh adalah datanya, dan itulah bentuk kegagalan yang
     * tidak pernah muncul di lingkungan tempat ia ditulis.
     */
    private function roles(string $tenantId): Collection
    {
        $policies = AppDataPolicy::query()->get(['code', 'protected_permissions']);

        $roles = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('duties:code,name,app_id')
            ->orderBy('name')
            ->get();

        if ($roles->isEmpty()) {
            return collect();
        }

        $turunan = $this->turunanRole($tenantId, self::daftarString($roles->pluck('id')));
        $permissionPerRole = $this->permissionPerRole(array_values(array_unique(array_merge(...array_values($turunan)))));

        return $roles->map(function (Role $role) use ($policies, $turunan, $permissionPerRole): array {
            $permissionCodes = array_values(array_unique(array_merge(
                ...array_map(
                    static fn (string $id): array => $permissionPerRole[$id] ?? [],
                    $turunan[$role->id] ?? [$role->id],
                ),
            )));

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

    /**
     * Peta role -> dirinya sendiri beserta seluruh turunannya, untuk semua role sekaligus.
     *
     * CTE yang sama seperti `RoleHierarchy::effectiveRoleIds()`, tetapi membawa serta role
     * asalnya pada tiap baris sehingga satu penelusuran menjawab seluruh daftar. Penjaga
     * siklusnya sama: `union` (bukan `union all`) menghentikan jalur yang bertemu kembali.
     *
     * @param  list<string>  $roleIds
     * @return array<string, list<string>>
     */
    private function turunanRole(string $tenantId, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));

        $rows = DB::select(
            <<<SQL
            with recursive reachable(root_id, role_id) as (
                select r.id, r.id
                from roles r
                where r.tenant_id = ? and r.is_active = true and r.id in ({$placeholders})
              union
                select reachable.root_id, link.child_role_id
                from security_role_children link
                join reachable on reachable.role_id = link.parent_role_id
                join roles child on child.id = link.child_role_id and child.is_active = true
                where link.tenant_id = ?
            )
            select root_id, role_id from reachable
            SQL,
            [$tenantId, ...$roleIds, $tenantId],
        );

        $peta = [];

        foreach ($rows as $row) {
            $peta[(string) $row->root_id][] = (string) $row->role_id;
        }

        return $peta;
    }

    /**
     * Peta role -> permission code yang datang dari duty-nya sendiri, untuk semua role sekaligus.
     *
     * @param  list<string>  $roleIds
     * @return array<string, list<string>>
     */
    private function permissionPerRole(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $rows = DB::table('security_role_duties as role_duties')
            ->join('security_duty_privileges as duty_privileges', 'duty_privileges.duty_code', '=', 'role_duties.duty_code')
            ->join('security_privilege_permissions as privilege_permissions', 'privilege_permissions.privilege_code', '=', 'duty_privileges.privilege_code')
            ->whereIn('role_duties.role_id', $roleIds)
            ->distinct()
            ->get(['role_duties.role_id', 'privilege_permissions.permission_code']);

        $peta = [];

        foreach ($rows as $row) {
            $peta[(string) $row->role_id][] = (string) $row->permission_code;
        }

        return $peta;
    }
}
