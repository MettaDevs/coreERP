<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Satuan ukur milik tenant, beserta konversinya.
 */
interface DaftarSatuan
{
    /**
     * @param  list<string>  $id
     * @return array<string, mixed>
     */
    public function resolusi(string $tenantId, array $id): array;

    /** @return array<string, mixed> */
    public function konversi(string $tenantId, string $dari, string $ke, string $nilai): array;
}
