<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\ModuleServices;

use App\Foundation\Vendor\Models\Vendor;
use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Membaca vendor untuk module, sebagai baris biasa, bukan model Core.
 *
 * Nama dibaca dari party pada saat dibaca, bukan disalin. Setiap pembacaan disaring `tenant_id`
 * pemanggil: id vendor tenant lain tidak pernah terbaca.
 */
final class VendorDirectoryCore implements VendorDirectory
{
    public function active(string $tenantId, string $legalEntityId, string $search = '', int $limit = 20): array
    {
        $search = trim($search);
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        return array_values(Vendor::query()
            ->with('party:id,name')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', Vendor::ACTIVE)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'ilike', $pattern)
                ->orWhereHas('party', fn (Builder $party) => $party->where('name', 'ilike', $pattern))))
            ->orderBy('number')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(self::row(...))
            ->all());
    }

    public function find(string $tenantId, string $vendorId): ?array
    {
        $vendor = Vendor::query()->with('party:id,name')->where('tenant_id', $tenantId)->find($vendorId);

        return $vendor === null ? null : self::row($vendor);
    }

    /** @return array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string} */
    private static function row(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'number' => $vendor->number,
            'name' => (string) $vendor->party->name,
            'tax_number' => $vendor->tax_number,
            'status' => $vendor->status,
            'legal_entity_id' => $vendor->legal_entity_id,
        ];
    }
}
