<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\Vendor;
use App\Support\Modules\Contracts\DaftarVendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Membaca vendor untuk module, sebagai baris biasa, bukan model Core.
 *
 * Nama dibaca dari party pada saat dibaca, bukan disalin. Setiap pembacaan disaring `tenant_id`
 * pemanggil: id vendor tenant lain tidak pernah terbaca.
 */
final class DaftarVendorCore implements DaftarVendor
{
    public function aktif(string $tenantId, string $legalEntityId, string $cari = '', int $batas = 20): array
    {
        $cari = trim($cari);
        $pola = '%'.addcslashes($cari, '\\%_').'%';

        return array_values(Vendor::query()
            ->with('party:id,name')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', Vendor::ACTIVE)
            ->when($cari !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'ilike', $pola)
                ->orWhereHas('party', fn (Builder $party) => $party->where('name', 'ilike', $pola))))
            ->orderBy('number')
            ->limit(max(1, min($batas, 100)))
            ->get()
            ->map(self::baris(...))
            ->all());
    }

    public function satu(string $tenantId, string $vendorId): ?array
    {
        $vendor = Vendor::query()->with('party:id,name')->where('tenant_id', $tenantId)->find($vendorId);

        return $vendor === null ? null : self::baris($vendor);
    }

    /** @return array{id: string, number: string, name: string, tax_number: ?string, status: string, legal_entity_id: string} */
    private static function baris(Vendor $vendor): array
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
