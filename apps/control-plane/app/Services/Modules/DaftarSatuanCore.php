<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Services\UnitOfMeasureService;
use App\Support\Modules\Contracts\DaftarSatuan;

/**
 * Meneruskan pertanyaan satuan ke layanan Core.
 */
final class DaftarSatuanCore implements DaftarSatuan
{
    public function __construct(private readonly UnitOfMeasureService $layanan) {}

    public function resolusi(string $tenantId, array $id): array
    {
        return $this->layanan->resolve($tenantId, $id);
    }

    public function konversi(string $tenantId, string $dari, string $ke, string $nilai): array
    {
        return $this->layanan->convert($tenantId, $dari, $ke, $nilai);
    }
}
