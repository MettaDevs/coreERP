<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Models\TenantMembership;
use App\Support\LaunchableAppCatalog;

/**
 * Permission Core yang efektif untuk satu keanggotaan, lewat rantai yang sama dengan module: role -> duty ->
 * privilege -> permission, dengan hierarki role (SEC-22). Pengganti `canManageAccess()`, penanda owner/admin
 * yang dulu menjaga semua layar setup Core sekaligus.
 *
 * Dibaca sekali per permintaan per keanggotaan; ikatannya `scoped()`, jadi ingatannya tidak terbawa ke
 * permintaan berikutnya pada pekerja yang hidup lama.
 */
final class CorePermissions
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(private readonly LaunchableAppCatalog $catalog) {}

    public function allows(?TenantMembership $membership, string $permission): bool
    {
        if ($membership === null || $membership->status !== 'active') {
            return false;
        }

        return in_array($permission, $this->of($membership), true);
    }

    /** @return list<string> */
    public function of(TenantMembership $membership): array
    {
        return $this->memo[(string) $membership->id] ??= $this->catalog->permissionsFor($membership, CoreSecurityCatalog::APP_ID);
    }

    /** Dipanggil sesudah role atau penugasan berubah di tengah permintaan yang sama. */
    public function forget(): void
    {
        $this->memo = [];
    }
}
