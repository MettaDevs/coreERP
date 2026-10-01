<?php

declare(strict_types=1);

namespace App\Foundation\UnitOfMeasure\ModuleServices;

use App\Foundation\UnitOfMeasure\Models\UnitOfMeasure;
use App\Foundation\UnitOfMeasure\Support\UnitOfMeasureService;
use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;

/**
 * Meneruskan pertanyaan satuan ke layanan Core.
 */
final class UnitOfMeasureDirectoryCore implements UnitOfMeasureDirectory
{
    public function __construct(private readonly UnitOfMeasureService $service) {}

    public function active(string $tenantId): array
    {
        // Query yang sama dengan `UnitOfMeasureDirectoryController::index()`, yang selama ini
        // melayaninya lewat HTTP. Keduanya harus memberi jawaban yang sama sampai controller itu
        // dibuang; disatukan pada F3-20.
        $units = UnitOfMeasure::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'symbol', 'decimal_places']);

        $result = [];

        foreach ($units as $row) {
            $result[] = [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'symbol' => $row->symbol,
                'decimal_places' => $row->decimal_places,
            ];
        }

        return $result;
    }

    public function resolve(string $tenantId, array $id): array
    {
        return $this->service->resolve($tenantId, $id);
    }

    public function convert(string $tenantId, string $from, string $to, string $value): array
    {
        return $this->service->convert($tenantId, $from, $to, $value);
    }
}
