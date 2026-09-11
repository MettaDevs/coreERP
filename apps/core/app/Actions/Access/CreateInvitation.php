<?php

namespace App\Actions\Access;

use App\Models\InvitationCode;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Support\DataPolicyScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateInvitation
{
    public function __construct(private readonly DataPolicyScopeResolver $scopeResolver) {}

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @param  array{system_role:string,assignments:list<array{role_id:string,policy_scopes:list<array{policy_code:string,legal_entity_id:?string,organization_id:?string,hierarchy_id:?string,include_descendants:bool}>}>}  $data
     * @return array{invitation:InvitationCode,code:string}
     */
    public function handle(TenantMembership $actor, array $data): array
    {
        if (! $actor->canManageAccess()) {
            throw new AuthorizationException;
        }
        if ($data['system_role'] === 'owner') {
            throw ValidationException::withMessages(['system_role' => 'Invitations cannot grant owner access.']);
        }

        $roleIds = Role::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('is_active', true)
            ->whereIn('id', array_column($data['assignments'], 'role_id'))
            ->pluck('id');
        if ($roleIds->count() !== count(array_unique(array_column($data['assignments'], 'role_id')))) {
            throw ValidationException::withMessages(['role_ids' => 'Role harus berasal dari tenant aktif.']);
        }
        $roles = Role::query()->whereIn('id', $roleIds)->get()->keyBy('id');
        $scopes = collect($data['assignments'])->flatMap(function (array $assignment) use ($roles, $actor): array {
            $role = $roles->get($assignment['role_id']);
            $this->scopeResolver->assertNoRedundantGrants($assignment['policy_scopes']);
            $resolved = collect($assignment['policy_scopes'])
                ->map(fn (array $scope): array => $this->scopeResolver->resolve($actor->tenant_id, $role, $scope));

            return $resolved->map(fn (array $scope): array => [
                'role_id' => $role->id,
                ...$scope,
            ])->all();
        });

        $plain = $this->generateCode();

        return DB::transaction(function () use ($actor, $data, $roleIds, $plain, $scopes): array {
            $invitation = InvitationCode::create([
                'tenant_id' => $actor->tenant_id,
                'code_hash' => self::hash($plain),
                'code_ciphertext' => Crypt::encryptString($plain),
                'system_role' => $data['system_role'],
                'label' => $data['label'] ?? null,
                'created_by' => $actor->user_id,
                'expires_at' => null,
            ]);
            $invitation->roles()->sync($roleIds);
            $rows = $scopes->map(fn (array $scope): array => [
                'id' => (string) Str::ulid(),
                'invitation_id' => $invitation->id,
                ...$scope,
                'created_at' => now(), 'updated_at' => now(),
            ])->all();
            if ($rows !== []) {
                DB::table('invitation_data_policy_scopes')->insert($rows);
            }

            return ['invitation' => $invitation, 'code' => $plain];
        });
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? ''));
    }

    private function generateCode(): string
    {
        $characters = '';
        for ($index = 0; $index < 16; $index++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 4));
    }
}
