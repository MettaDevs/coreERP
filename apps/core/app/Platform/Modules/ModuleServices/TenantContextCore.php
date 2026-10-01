<?php

declare(strict_types=1);

namespace App\Platform\Modules\ModuleServices;

use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\TenantContext;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tenant aktif diambil dari permintaan yang sedang dilayani.
 *
 * Melempar bila tidak ada tenant aktif, bukan mengembalikan null. Module yang menerima null
 * akan meneruskannya ke query, dan query tanpa penyaringan tenant membaca data seluruh
 * pelanggan. Gagal menutup, bukan gagal membuka — sama seperti `TenantScope`.
 */
final class TenantContextCore implements TenantContext
{
    public function __construct(
        private readonly Request $request,
        private readonly CurrentWorkspace $workspace,
    ) {}

    public function tenantId(): string
    {
        $membership = $this->workspace->membership($this->request);

        if ($membership === null) {
            throw new RuntimeException('Tidak ada tenant aktif pada permintaan ini.');
        }

        return (string) $membership->tenant_id;
    }

    public function legalEntityId(): ?string
    {
        $membership = $this->workspace->membership($this->request);

        if ($membership === null) {
            return null;
        }

        $legalEntity = $this->workspace->legalEntity($this->request, $membership);

        return $legalEntity === null ? null : (string) $legalEntity->id;
    }

    public function orgUnitId(): ?string
    {
        $membership = $this->workspace->membership($this->request);

        if ($membership === null) {
            return null;
        }

        $unit = $this->workspace->operatingUnit($this->request, $membership);

        return $unit === null ? null : (string) $unit->id;
    }
}
