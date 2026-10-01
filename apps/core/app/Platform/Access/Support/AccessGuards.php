<?php

declare(strict_types=1);

namespace App\Platform\Access\Support;

use App\Platform\Access\Models\Role;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Validation\ValidationException;

/**
 * Dua aturan yang menggantikan perlindungan owner/admin sejak keduanya dihapus (SEC-22, TODO feed posting 7.4):
 *
 * 1. **Role Owner hanya diberikan dan dicabut oleh pemegang role Owner.** Padanan D365: hanya System administrator
 *    yang boleh memberikan role System administrator. Tanpa ini, pemegang *Kelola akses* dapat memberi dirinya
 *    role Owner dan memperoleh semua duty.
 * 2. **Selalu ada minimal satu anggota aktif yang dapat mengelola akses.** Perubahan yang membuat tidak ada lagi
 *    pemegang permission `core.access.update` ditolak, supaya tenant tidak terkunci dari layar aksesnya sendiri.
 *
 * Keduanya diperiksa di dalam transaksi perubahannya, sesudah perubahan ditulis, sehingga yang dinilai adalah
 * keadaan sesudahnya.
 */
final class AccessGuards
{
    public static function holdsOwnerRole(TenantMembership $membership): bool
    {
        return $membership->status === 'active' && $membership->roleAssignments()
            ->where('status', 'active')
            ->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->whereHas('role', fn ($query) => $query->where('is_owner', true)->where('is_active', true))
            ->exists();
    }

    /**
     * @param  list<string>  $roleIds  role yang ditambahkan atau dicabut oleh perubahan ini
     *
     * @throws ValidationException
     */
    public static function assertMayGrantRoles(TenantMembership $actor, array $roleIds, string $field = 'role_ids'): void
    {
        if ($roleIds === []) {
            return;
        }

        $touchesOwner = Role::query()->where('tenant_id', $actor->tenant_id)->whereIn('id', $roleIds)->where('is_owner', true)->exists();
        if ($touchesOwner && ! self::holdsOwnerRole($actor)) {
            throw ValidationException::withMessages([
                $field => 'Hanya pemegang role Owner yang dapat memberikan atau mencabut role Owner.',
            ]);
        }
    }

    /** @throws ValidationException */
    public static function assertNotLockedOut(string $tenantId): void
    {
        $permissions = app(CorePermissions::class);
        $permissions->forget();

        $someoneCanManage = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('roleAssignments', fn ($query) => $query->where('status', 'active'))
            ->get()
            ->contains(fn (TenantMembership $member): bool => $permissions->allows($member, CoreSecurityCatalog::ACCESS_UPDATE));

        if (! $someoneCanManage) {
            throw ValidationException::withMessages([
                'access' => 'Perubahan ini membuat tidak ada lagi anggota yang dapat mengelola akses. Sisakan minimal satu anggota aktif yang memegang duty Kelola akses.',
            ]);
        }
    }
}
