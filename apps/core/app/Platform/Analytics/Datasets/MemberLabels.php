<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Datasets;

use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\Analytics\SharedDimensionResolver;
use App\Platform\Modules\Contracts\DataClass;

/**
 * Nama pengguna yang pernah menjadi anggota tenant, untuk kolom berisi id pengguna (`users.id`) seperti
 * "dicatat oleh". Keanggotaan yang sudah tidak aktif ikut, supaya data lama tetap bernama; pengguna yang
 * tidak pernah menjadi anggota tenant itu tidak berlabel.
 *
 * Nama orang adalah data pribadi, jadi labelnya hanya diberikan kepada principal yang berhak
 * (`SharedDimensionRegistry::labels()`).
 */
final class MemberLabels implements SharedDimensionResolver
{
    public function dimension(): SharedDimension
    {
        return SharedDimension::User;
    }

    public function labelClassification(): DataClass
    {
        return DataClass::EndUserIdentifiableInformation;
    }

    public function labels(string $tenantId, array $ids): array
    {
        // Id pengguna berupa bilangan; nilai lain tidak mungkin cocok dan akan ditolak PostgreSQL.
        $ids = array_values(array_filter($ids, static fn (string $id): bool => ctype_digit($id)));
        if ($ids === []) {
            return [];
        }

        $labels = [];
        foreach (User::query()
            ->join('tenant_memberships', 'tenant_memberships.user_id', '=', 'users.id')
            ->where('tenant_memberships.tenant_id', $tenantId)
            ->whereIn('users.id', $ids)
            ->distinct()
            ->get(['users.id', 'users.name']) as $user) {
            $labels[$user->id] = $user->name;
        }

        return $labels;
    }
}
