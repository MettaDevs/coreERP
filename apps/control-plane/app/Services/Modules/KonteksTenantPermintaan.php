<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\CurrentWorkspace;
use App\Support\Modules\Contracts\KonteksTenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tenant aktif diambil dari permintaan yang sedang dilayani.
 *
 * Melempar bila tidak ada tenant aktif, bukan mengembalikan null. Module yang menerima null
 * akan meneruskannya ke query, dan query tanpa penyaringan tenant membaca data seluruh
 * pelanggan. Gagal menutup, bukan gagal membuka — sama seperti `TenantScope`.
 */
final class KonteksTenantPermintaan implements KonteksTenant
{
    public function __construct(
        private readonly Request $permintaan,
        private readonly CurrentWorkspace $workspace,
    ) {}

    public function tenantId(): string
    {
        $membership = $this->workspace->membership($this->permintaan);

        if ($membership === null) {
            throw new RuntimeException('Tidak ada tenant aktif pada permintaan ini.');
        }

        return (string) $membership->tenant_id;
    }

    public function legalEntityId(): ?string
    {
        $membership = $this->workspace->membership($this->permintaan);

        if ($membership === null) {
            return null;
        }

        $legalEntity = $this->workspace->legalEntity($this->permintaan, $membership);

        return $legalEntity === null ? null : (string) $legalEntity->id;
    }

    public function orgUnitId(): ?string
    {
        $membership = $this->workspace->membership($this->permintaan);

        if ($membership === null) {
            return null;
        }

        $unit = $this->workspace->operatingUnit($this->permintaan, $membership);

        return $unit === null ? null : (string) $unit->id;
    }
}
