<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\UnitOfMeasure;
use App\Services\UnitOfMeasureService;
use App\Support\Modules\Contracts\DaftarSatuan;

/**
 * Meneruskan pertanyaan satuan ke layanan Core.
 */
final class DaftarSatuanCore implements DaftarSatuan
{
    public function __construct(private readonly UnitOfMeasureService $layanan) {}

    public function aktif(string $tenantId): array
    {
        // Query yang sama dengan `UnitOfMeasureDirectoryController::index()`, yang selama ini
        // melayaninya lewat HTTP. Keduanya harus memberi jawaban yang sama sampai controller itu
        // dibuang; disatukan pada F3-20.
        $satuan = UnitOfMeasure::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'symbol', 'decimal_places']);

        $hasil = [];

        foreach ($satuan as $baris) {
            $hasil[] = [
                'id' => $baris->id,
                'code' => $baris->code,
                'name' => $baris->name,
                'symbol' => $baris->symbol,
                'decimal_places' => $baris->decimal_places,
            ];
        }

        return $hasil;
    }

    public function resolusi(string $tenantId, array $id): array
    {
        return $this->layanan->resolve($tenantId, $id);
    }

    public function konversi(string $tenantId, string $dari, string $ke, string $nilai): array
    {
        return $this->layanan->convert($tenantId, $dari, $ke, $nilai);
    }
}
