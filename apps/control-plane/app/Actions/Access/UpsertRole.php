<?php

namespace App\Actions\Access;

use App\Models\Role;
use App\Models\SecurityDuty;
use App\Models\TenantMembership;
use App\Support\RoleHierarchy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertRole
{
    public function __construct(private readonly RoleHierarchy $hierarchy) {}

    /** @param array{name:string,duty_codes:list<string>,child_role_ids?:list<string>} $data */
    public function handle(TenantMembership $actor, array $data, ?Role $role = null): Role
    {
        if (! $actor->canManageAccess() || ($role && $role->tenant_id !== $actor->tenant_id)) {
            throw new AuthorizationException;
        }
        $entitledAppIds = DB::table('tenant_app_entitlements')
            ->where('tenant_id', $actor->tenant_id)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('app_id');
        $validDuties = SecurityDuty::query()
            ->where(function ($query) use ($actor, $entitledAppIds): void {
                $query->whereIn('app_id', $entitledAppIds)
                    ->orWhere(function ($query) use ($actor): void {
                        $query->where('tenant_id', $actor->tenant_id)
                            ->where('source', 'custom')
                            ->where('status', 'active');
                    });
            })
            ->whereIn('code', $data['duty_codes'])
            ->pluck('code');

        if ($validDuties->count() !== count(array_unique($data['duty_codes']))) {
            throw ValidationException::withMessages(['duty_codes' => 'Tanggung jawab harus berasal dari produk yang boleh digunakan tenant.']);
        }

        $childRoleIds = array_values(array_unique($data['child_role_ids'] ?? []));
        if ($childRoleIds !== []) {
            $ownedChildren = Role::query()
                ->where('tenant_id', $actor->tenant_id)
                ->whereIn('id', $childRoleIds)
                ->pluck('id');

            if ($ownedChildren->count() !== count($childRoleIds)) {
                throw ValidationException::withMessages(['child_role_ids' => 'Role turunan harus berasal dari tenant yang sama.']);
            }
        }

        return DB::transaction(function () use ($actor, $data, $role, $validDuties, $childRoleIds): Role {
            $role ??= new Role(['tenant_id' => $actor->tenant_id]);
            $role->fill([
                'name' => $data['name'],
                'is_active' => true,
            ])->save();
            $role->duties()->sync($validDuties);

            // Lingkaran membuat hak tidak dapat dihitung dan menggantung ekspansi
            // role. Ia ditolak sebelum tersimpan, bukan disaring saat dibaca.
            if ($this->hierarchy->wouldCreateCycle($actor->tenant_id, $role->id, $childRoleIds)) {
                throw ValidationException::withMessages(['child_role_ids' => 'Susunan role tidak boleh membentuk lingkaran.']);
            }

            $role->children()->sync(
                collect($childRoleIds)->mapWithKeys(fn (string $id): array => [$id => ['tenant_id' => $actor->tenant_id]])->all(),
            );

            return $role->load('duties', 'children');
        });
    }
}
