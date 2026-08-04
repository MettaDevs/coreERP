<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Hierarchy security role mengikuti Dynamics 365: parent mewarisi duty seluruh
 * child-nya, dan satu role boleh mempunyai lebih dari satu parent maupun child.
 * Karena itu relasinya graph berarah tanpa siklus, bukan tree, dan penelusuran
 * harus tahan terhadap jalur yang bertemu kembali (diamond).
 */
class RoleHierarchy
{
    /**
     * Seluruh role yang haknya ikut berlaku ketika `$roleIds` diberikan:
     * role itu sendiri beserta semua turunannya.
     *
     * Role non-aktif tidak ikut dan juga tidak menjadi jembatan ke turunannya.
     * Menonaktifkan role harus benar-benar menghentikan haknya, bukan hanya
     * menyembunyikannya dari daftar.
     *
     * @param  list<string>  $roleIds
     * @return list<string>
     */
    public function effectiveRoleIds(string $tenantId, array $roleIds, bool $activeOnly = true): array
    {
        if ($roleIds === []) {
            return [];
        }

        $activeFilter = $activeOnly ? 'and r.is_active = true' : '';
        $activeChildFilter = $activeOnly ? 'and child.is_active = true' : '';
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));

        $rows = DB::select(
            <<<SQL
            with recursive reachable(role_id) as (
                select r.id
                from roles r
                where r.tenant_id = ? {$activeFilter} and r.id in ({$placeholders})
              union
                select link.child_role_id
                from security_role_children link
                join reachable on reachable.role_id = link.parent_role_id
                join roles child on child.id = link.child_role_id {$activeChildFilter}
                where link.tenant_id = ?
            )
            select role_id from reachable
            SQL,
            [$tenantId, ...$roleIds, $tenantId],
        );

        return array_values(array_map(fn (object $row): string => (string) $row->role_id, $rows));
    }

    /**
     * Apakah menjadikan `$childIds` sebagai child dari `$parentId` membuat siklus.
     *
     * Siklus dihitung tanpa memandang status aktif: role yang dinonaktifkan hari
     * ini dapat diaktifkan lagi besok, dan graph yang tersimpan harus tetap sah.
     *
     * @param  list<string>  $childIds
     */
    public function wouldCreateCycle(string $tenantId, string $parentId, array $childIds): bool
    {
        if (in_array($parentId, $childIds, true)) {
            return true;
        }

        return in_array($parentId, $this->effectiveRoleIds($tenantId, $childIds, activeOnly: false), true);
    }
}
