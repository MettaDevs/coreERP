<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Mengajukan dokumen ke alur persetujuan.
 *
 * Mesin Core menerima objek tipe dan versi. Antarmuka ini menerima kode tipe, dan
 * pembungkusnya yang mencari versi yang berlaku.
 */
interface MesinWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, status: string}
     */
    public function ajukan(string $tenantId, string $appId, string $kodeTipe, string $kunciIdempoten, array $data): array;
}
