<?php

namespace App\Actions\Access;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use App\Support\OrganizationScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateMembership
{
    public function __construct(private readonly OrganizationScopeResolver $scopeResolver) {}

    /** @param array{system_role:string,role_ids:list<string>,organization_id:?string,hierarchy_id:?string,include_descendants:bool} $data */
    public function handle(TenantMembership $actor, TenantMembership $target, array $data): TenantMembership
    {
        $targetIsOwner = $target->system_role === 'owner';
        if (! $actor->canManageAccess()
            || $actor->tenant_id !== $target->tenant_id
            || ($targetIsOwner && $actor->id !== $target->id)) {
            throw new AuthorizationException;
        }
        if (($targetIsOwner && $data['system_role'] !== 'owner') || (! $targetIsOwner && $data['system_role'] === 'owner')) {
            throw ValidationException::withMessages([
                'system_role' => 'Role pemilik tidak dapat dipindahkan lewat layar ini.',
            ]);
        }

        $roles = Role::query()->where('tenant_id', $actor->tenant_id)->where('is_active', true)->whereIn('id', $data['role_ids'])->get();
        if ($roles->count() !== count(array_unique($data['role_ids']))) {
            throw ValidationException::withMessages(['role_ids' => 'Pilih tanggung jawab bisnis yang tersedia untuk bisnis ini.']);
        }
        $scope = $this->scopeResolver->resolve($actor->tenant_id, $data);

        return DB::transaction(function () use ($target, $targetIsOwner, $data, $roles, $scope): TenantMembership {
            if (! $targetIsOwner) {
                $target->update(['system_role' => $data['system_role']]);
            }
            $target->roleAssignments()->delete();
            foreach ($roles as $role) {
                RoleAssignment::create([
                    'membership_id' => $target->id,
                    'role_id' => $role->id,
                    'source' => 'manual',
                    'status' => 'active',
                    'valid_from' => now(),
                ])->organizationScope()->create($scope);
            }

            return $target->load('roleAssignments.role');
        });
    }
}
