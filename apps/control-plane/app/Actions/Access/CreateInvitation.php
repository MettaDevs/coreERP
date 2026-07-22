<?php

namespace App\Actions\Access;

use App\Models\InvitationCode;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Support\OrganizationScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInvitation
{
    public function __construct(private readonly OrganizationScopeResolver $scopeResolver) {}

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @param  array{system_role:string,role_ids:list<string>,organization_id:?string,hierarchy_id:?string,include_descendants:bool}  $data
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
            ->whereIn('id', $data['role_ids'])
            ->pluck('id');
        if ($roleIds->count() !== count(array_unique($data['role_ids']))) {
            throw ValidationException::withMessages(['role_ids' => 'Role harus berasal dari tenant aktif.']);
        }
        $scope = $this->scopeResolver->resolve($actor->tenant_id, $data);

        $plain = $this->generateCode();

        return DB::transaction(function () use ($actor, $data, $roleIds, $plain, $scope): array {
            $invitation = InvitationCode::create([
                'tenant_id' => $actor->tenant_id,
                'code_hash' => self::hash($plain),
                'system_role' => $data['system_role'],
                'organization_id' => $scope['organization_id'],
                'hierarchy_id' => $scope['hierarchy_id'],
                'include_descendants' => $scope['include_descendants'],
                'created_by' => $actor->user_id,
                'expires_at' => now()->addDays(7),
            ]);
            $invitation->roles()->sync($roleIds);

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
