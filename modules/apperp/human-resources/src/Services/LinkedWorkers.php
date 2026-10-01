<?php

declare(strict_types=1);

namespace Modules\Apperp\HumanResources\Services;

use App\Platform\Modules\Contracts\LinkedWorkerResolver;
use Modules\Apperp\HumanResources\Models\Worker;

/**
 * Pekerja yang tertaut ke akun pengguna, untuk layar anggota Core (TODO analisa gap BC 9.2).
 *
 * Core memanggilnya dengan tenant yang ditanyakan sebagai tenant aktif, jadi `BelongsToTenant` menyaring barisnya;
 * pekerja yang diarsipkan tidak ikut karena `SoftDeletes`.
 */
final class LinkedWorkers implements LinkedWorkerResolver
{
    public function moduleId(): string
    {
        return 'human-resources';
    }

    public function forMemberships(string $tenantId, array $membershipIds): array
    {
        return Worker::query()
            ->whereIn('core_membership_id', $membershipIds)
            ->get(['core_membership_id', 'name', 'personnel_number'])
            ->mapWithKeys(fn (Worker $worker): array => [
                (string) $worker->core_membership_id => ['name' => $worker->name, 'personnel_number' => $worker->personnel_number],
            ])
            ->all();
    }
}
