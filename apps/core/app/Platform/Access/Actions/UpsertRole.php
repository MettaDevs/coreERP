<?php

namespace App\Platform\Access\Actions;

use App\Platform\Access\Models\Role;
use App\Platform\Access\Support\AccessGuards;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Access\Support\RoleHierarchy;
use App\Platform\Access\Support\TenantProducts;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class UpsertRole
{
    public function __construct(private readonly RoleHierarchy $hierarchy) {}

    /**
     * `$expectedVersion` wajib saat mengubah role yang sudah ada: versi yang dibuka penggunanya.
     *
     * @param  array{name:string,duty_codes:list<string>,child_role_ids?:list<string>}  $data
     */
    public function handle(TenantMembership $actor, array $data, ?Role $role = null, ?int $expectedVersion = null): Role
    {
        if (! $actor->hasCorePermission(CoreSecurityCatalog::ACCESS_UPDATE) || ($role && $role->tenant_id !== $actor->tenant_id)) {
            throw new AuthorizationException;
        }
        // Role Owner memegang semua duty yang sah secara otomatis (`OwnerRoleDuties`); menyuntingnya tidak mengubah
        // apa pun selain membingungkan jejaknya.
        if ($role?->is_owner) {
            throw ValidationException::withMessages(['name' => 'Role Owner diatur otomatis dan selalu memegang semua duty, jadi tidak dapat diubah.']);
        }
        $validDuties = TenantProducts::duties($actor->tenant_id)
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
            // Role induk mewarisi semua duty turunannya. Owner sebagai turunan akan membuat role mana pun setara
            // Owner, dan role itu dapat diberikan tanpa melewati penjaga Owner (`AccessGuards`).
            if (Role::query()->whereIn('id', $childRoleIds)->where('is_owner', true)->exists()) {
                throw ValidationException::withMessages(['child_role_ids' => 'Role Owner tidak dapat dijadikan turunan role lain.']);
            }
        }

        return DB::transaction(function () use ($actor, $data, $role, $expectedVersion, $validDuties, $childRoleIds): Role {
            if ($role !== null) {
                RowVersion::claim($role, $expectedVersion ?? throw new LogicException('Mengubah role wajib membawa versi yang dibuka.'));
            }
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

            AccessGuards::assertNotLockedOut($actor->tenant_id);

            return $role->refresh()->load('duties', 'children');
        });
    }
}
