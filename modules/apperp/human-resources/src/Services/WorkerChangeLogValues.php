<?php

declare(strict_types=1);

namespace Modules\Apperp\HumanResources\Services;

use App\Platform\Modules\Contracts\ChangeLogValueResolver;
use App\Platform\Modules\Contracts\DirektoriOrganisasi;
use Modules\Apperp\HumanResources\Models\Worker;

/**
 * Nilai log perubahan pekerja dalam bentuk yang dibaca orang: akun pengguna yang ditautkan tampil sebagai
 * nama anggota tenant, bukan id keanggotaannya.
 */
final class WorkerChangeLogValues implements ChangeLogValueResolver
{
    public function __construct(private readonly DirektoriOrganisasi $organisasi) {}

    public function table(): string
    {
        return (new Worker)->getTable();
    }

    public function display(string $tenantId, string $field, array $values): array
    {
        if ($field !== 'core_membership_id') {
            return [];
        }

        return collect($this->organisasi->anggota($tenantId))
            ->whereIn('id', $values)
            ->mapWithKeys(fn (array $anggota): array => [(string) $anggota['id'] => (string) $anggota['nama']])
            ->all();
    }
}
