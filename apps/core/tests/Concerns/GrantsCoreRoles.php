<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use App\Support\Access\CorePermissions;
use App\Support\Access\OwnerRoleDuties;
use Illuminate\Support\Str;

/**
 * Memberi keanggotaan fixture haknya lewat security role, pengganti `system_role` sejak owner/admin dihapus dari
 * keanggotaan (SEC-22).
 */
trait GrantsCoreRoles
{
    /**
     * Menjadikan keanggotaan pemegang role Owner tenantnya. Role Owner dibuat bila tenant belum punya, lalu duty-nya
     * disamakan dengan semua duty yang sah — sama seperti tenant yang lahir lewat `RegisterBusiness`.
     */
    protected function makeOwner(TenantMembership $membership): TenantMembership
    {
        $role = Role::query()->where('tenant_id', $membership->tenant_id)->where('is_owner', true)->first()
            ?? Role::query()->create(['tenant_id' => $membership->tenant_id, 'name' => 'Owner', 'is_active' => true, 'is_owner' => true]);
        app(OwnerRoleDuties::class)->sync($role);

        RoleAssignment::query()->firstOrCreate(
            ['membership_id' => $membership->id, 'role_id' => $role->id, 'source' => 'automatic'],
            ['status' => 'active', 'valid_from' => now()->subMinute()],
        );
        app(CorePermissions::class)->forget();

        return $membership;
    }

    /**
     * Memberi keanggotaan satu role baru yang memegang duty ini saja, misalnya `core.vendor.inquire`.
     *
     * @param  list<string>  $duties
     */
    protected function grantDuties(TenantMembership $membership, array $duties): TenantMembership
    {
        $role = Role::query()->create(['tenant_id' => $membership->tenant_id, 'name' => 'Role '.Str::random(8), 'is_active' => true]);
        $role->duties()->sync($duties);

        RoleAssignment::query()->create([
            'membership_id' => $membership->id, 'role_id' => $role->id, 'source' => 'manual',
            'status' => 'active', 'valid_from' => now()->subMinute(),
        ]);
        app(CorePermissions::class)->forget();

        return $membership;
    }
}
