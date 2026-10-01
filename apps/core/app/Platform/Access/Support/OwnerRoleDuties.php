<?php

declare(strict_types=1);

namespace App\Platform\Access\Support;

use App\Platform\Access\Models\Role;

/**
 * Role Owner setiap tenant selalu memegang semua duty yang sah untuk tenant itu (keputusan pemilik produk,
 * 25 September 2026): duty Core, duty app yang dibeli, dan duty buatan tenant sendiri yang aktif.
 *
 * Sebelumnya Owner hanya menyalin duty saat tenant lahir, sehingga duty yang lahir kemudian — misalnya posting
 * group aset di area 8 feed posting — tidak pernah sampai ke Owner tenant lama. Sekarang Owner disamakan lagi
 * setiap kali katalog berubah: saat tenant lahir (`RegisterBusiness`), saat sebuah app didaftarkan atau
 * diperbarui (`RegisterAppCatalog`), dan saat katalog Core berubah (migration katalog Core menyelaraskannya sendiri).
 *
 * Duty yang tidak lagi sah dilepas juga, jadi Owner tidak pernah menyimpan duty app yang sudah tidak dibeli.
 */
final class OwnerRoleDuties
{
    public function syncAll(): void
    {
        Role::query()->where('is_owner', true)->each(fn (Role $role) => $this->sync($role));
    }

    public function syncTenant(string $tenantId): void
    {
        Role::query()->where('tenant_id', $tenantId)->where('is_owner', true)->each(fn (Role $role) => $this->sync($role));
    }

    public function sync(Role $role): void
    {
        $role->duties()->sync(TenantProducts::duties((string) $role->tenant_id)->pluck('code')->all());
    }
}
