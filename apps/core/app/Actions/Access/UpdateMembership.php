<?php

namespace App\Actions\Access;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use App\Support\DataPolicyScopeResolver;
use App\Support\SodConflictEvaluator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateMembership
{
    public function __construct(
        private readonly DataPolicyScopeResolver $scopeResolver,
        private readonly SodConflictEvaluator $sod,
    ) {}

    /** @param array{system_role:string,assignments:list<array{role_id:string,policy_scopes:list<array<string,mixed>>}>} $data */
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

        $roleIds = collect($data['assignments'])->pluck('role_id')->unique()->values()->all();
        $roles = Role::query()->where('tenant_id', $actor->tenant_id)->where('is_active', true)->whereIn('id', $roleIds)->get()->keyBy('id');
        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages(['role_ids' => 'Pilih tanggung jawab bisnis yang tersedia untuk bisnis ini.']);
        }
        $this->sod->assertManualAssignmentAllowed($target, $roleIds);
        foreach ($data['assignments'] as $assignment) {
            $this->scopeResolver->assertNoRedundantGrants($assignment['policy_scopes']);
        }

        return DB::transaction(function () use ($actor, $target, $targetIsOwner, $data, $roles): TenantMembership {
            if (! $targetIsOwner) {
                $target->update(['system_role' => $data['system_role']]);
            }
            $target->roleAssignments()->where('source', 'manual')->delete();
            foreach ($data['assignments'] as $input) {
                $assignment = RoleAssignment::create([
                    'membership_id' => $target->id,
                    'role_id' => $input['role_id'],
                    'source' => 'manual',
                    'status' => 'active',
                    'valid_from' => now(),
                ]);

                foreach ($input['policy_scopes'] as $scope) {
                    $assignment->dataPolicyScopes()->create([
                        ...$this->scopeResolver->resolve($actor->tenant_id, $roles->get($input['role_id']), $scope),
                        'tenant_id' => $actor->tenant_id,
                        'valid_from' => now(),
                    ]);
                }
            }

            DB::table('access_audit_events')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $actor->tenant_id,
                'membership_id' => $target->id,
                'action' => 'access.manual-assignment.updated',
                'payload' => json_encode(['actor_membership_id' => $actor->id, 'assignments' => $data['assignments']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $target->load('roleAssignments.role', 'roleAssignments.dataPolicyScopes');
        });
    }
}
