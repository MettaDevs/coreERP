<?php

namespace App\Actions\Access;

use App\Models\InvitationCode;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Support\DataPolicyScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mengubah role dan batas data sebuah kode undangan yang sudah terbit.
 *
 * Kodenya sendiri tidak pernah diganti: `code_hash` dan `code_ciphertext` tidak
 * disentuh, sehingga kode yang sudah terlanjur dibagikan tetap berlaku. Yang
 * berubah hanya apa yang diterima penukar **berikutnya** — anggota yang sudah
 * bergabung memegang role assignment miliknya sendiri dan tidak ikut berubah.
 *
 * Karena kode sudah beredar saat diubah, setiap perubahan dicatat ke
 * `access_audit_events` lengkap dengan keadaan sebelum dan sesudahnya.
 */
class UpdateInvitation
{
    public function __construct(private readonly DataPolicyScopeResolver $scopeResolver) {}

    /** @param  array{system_role:string,label:?string,assignments:list<array{role_id:string,policy_scopes:list<array{policy_code:string,legal_entity_id:?string,organization_id:?string,hierarchy_id:?string,include_descendants:bool,unrestricted:bool}>}>}  $data */
    public function handle(TenantMembership $actor, InvitationCode $invitation, array $data): InvitationCode
    {
        if (! $actor->canManageAccess() || $invitation->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }
        if ($data['system_role'] === 'owner') {
            throw ValidationException::withMessages(['system_role' => 'Invitations cannot grant owner access.']);
        }
        // Kode yang dicabut tidak dapat ditukar siapa pun, jadi mengubahnya
        // tidak mengubah akses siapa pun — hanya mengaburkan jejak audit.
        if ($invitation->revoked_at !== null) {
            throw ValidationException::withMessages(['id' => 'Kode yang sudah dicabut tidak dapat diubah. Buat kode baru.']);
        }

        $roleIds = collect($data['assignments'])->pluck('role_id')->unique()->values();
        $roles = Role::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('is_active', true)
            ->whereIn('id', $roleIds)
            ->get()
            ->keyBy('id');
        if ($roles->count() !== $roleIds->count()) {
            throw ValidationException::withMessages(['role_ids' => 'Role harus berasal dari tenant aktif.']);
        }

        $scopes = collect($data['assignments'])->flatMap(function (array $assignment) use ($actor, $roles): array {
            $this->scopeResolver->assertNoRedundantGrants($assignment['policy_scopes']);

            return collect($assignment['policy_scopes'])
                ->map(fn (array $scope): array => [
                    'role_id' => $assignment['role_id'],
                    ...$this->scopeResolver->resolve($actor->tenant_id, $roles->get($assignment['role_id']), $scope),
                ])
                ->all();
        });

        $before = $this->snapshot($invitation);

        return DB::transaction(function () use ($actor, $invitation, $data, $roleIds, $scopes, $before): InvitationCode {
            $invitation->update([
                'system_role' => $data['system_role'],
                'label' => $data['label'] ?? null,
            ]);
            $invitation->roles()->sync($roleIds->all());

            DB::table('invitation_data_policy_scopes')->where('invitation_id', $invitation->id)->delete();
            $rows = $scopes->map(fn (array $scope): array => [
                'id' => (string) Str::ulid(),
                'invitation_id' => $invitation->id,
                ...$scope,
                'created_at' => now(), 'updated_at' => now(),
            ])->all();
            if ($rows !== []) {
                DB::table('invitation_data_policy_scopes')->insert($rows);
            }

            DB::table('access_audit_events')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $actor->tenant_id,
                'membership_id' => $actor->id,
                'action' => 'access.invitation.updated',
                'payload' => json_encode([
                    'invitation_id' => $invitation->id,
                    'actor_membership_id' => $actor->id,
                    'redeemed_count' => self::redeemedCount($invitation),
                    'before' => $before,
                    'after' => $this->snapshot($invitation->refresh()),
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $invitation->load('roles');
        });
    }

    /** Berapa anggota yang sudah menukarkan kode ini. */
    public static function redeemedCount(InvitationCode $invitation): int
    {
        return DB::table('role_assignments')
            ->where('source_reference', 'invitation:'.$invitation->id)
            ->distinct()
            ->count('membership_id');
    }

    /** @return array{system_role:string,label:?string,role_ids:array<int,string>,policy_scopes:array<int,array<string,mixed>>} */
    private function snapshot(InvitationCode $invitation): array
    {
        return [
            'system_role' => $invitation->system_role,
            'label' => $invitation->label,
            'role_ids' => $invitation->roles()->pluck('roles.id')->sort()->values()->all(),
            'policy_scopes' => DB::table('invitation_data_policy_scopes')
                ->where('invitation_id', $invitation->id)
                ->orderBy('role_id')
                ->orderBy('policy_code')
                ->get(['role_id', 'policy_code', 'legal_entity_id', 'organization_id', 'hierarchy_id', 'hierarchy_version_id', 'include_descendants'])
                ->map(fn (object $scope): array => (array) $scope)
                ->values()
                ->all(),
        ];
    }
}
