<?php

declare(strict_types=1);

namespace App\Foundation\Vendor\ModuleServices;

use App\Foundation\Vendor\Models\Vendor;
use App\Foundation\Vendor\Support\VendorSearch;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Membaca vendor untuk module, sebagai baris biasa, bukan model Core.
 *
 * Nama dibaca dari party pada saat dibaca, bukan disalin. Setiap pembacaan disaring `tenant_id`
 * pemanggil: id vendor tenant lain tidak pernah terbaca.
 */
final class VendorDirectoryCore implements VendorDirectory
{
    public function findMany(string $tenantId, array $ids): array
    {
        return Vendor::query()->with('party:id,name')->where('tenant_id', $tenantId)->whereIn('id', $ids)
            ->get()->mapWithKeys(fn (Vendor $vendor) => [$vendor->id => self::row($vendor)])->all();
    }

    public function addNames(Builder $query, string $tenantId, array $fields): void
    {
        foreach ($fields as $alias => $column) {
            $query->selectSub(Vendor::query()->join('parties as directory_party', function ($join): void {
                $join->on('directory_party.id', '=', 'vendors.party_id')->on('directory_party.tenant_id', '=', 'vendors.tenant_id');
            })->where('vendors.tenant_id', $tenantId)->whereNull('directory_party.deleted_at')
                ->whereColumn('vendors.id', $column)->select('directory_party.name')->toBase()->limit(1), $alias);
        }
    }

    public function lookup(string $tenantId, string $legalEntityId, array $input): array
    {
        $query = Vendor::query()->join('parties as list_party', function ($join): void {
            $join->on('list_party.id', '=', 'vendors.party_id')->on('list_party.tenant_id', '=', 'vendors.tenant_id');
        })->where('vendors.tenant_id', $tenantId)->where('vendors.legal_entity_id', $legalEntityId)
            ->where('vendors.status', Vendor::ACTIVE)->whereNull('list_party.deleted_at')
            ->select(['vendors.id', 'vendors.number', 'list_party.name', 'vendors.tax_number', 'vendors.status', 'vendors.legal_entity_id']);

        $request = Request::create('/lookup', 'GET', $input);
        $filter = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        VendorSearch::apply($query, $tenantId, (string) ($filter['q'] ?? ''));

        return IncrementalList::query($request, $query->toBase(),
            ['id' => 'id', 'number' => 'number', 'name' => 'name'], [], 'number');
    }

    public function active(string $tenantId, string $legalEntityId, string $search = '', int $limit = 20): array
    {
        $search = trim($search);

        return array_values(Vendor::query()
            ->with('party:id,name')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', Vendor::ACTIVE)
            ->tap(fn ($query) => VendorSearch::apply($query, $tenantId, $search))
            ->orderBy('number')->orderBy('id')
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

    public function forParty(string $tenantId, string $partyId): array
    {
        return array_values(Vendor::query()->where('tenant_id', $tenantId)->where('party_id', $partyId)->with('legalEntity')->get()
            ->map(fn (Vendor $vendor): array => ['id' => $vendor->id, 'number' => $vendor->number, 'status' => $vendor->status, 'legal_entity_id' => $vendor->legal_entity_id, 'company' => $vendor->legalEntity?->name])->all());
    }
}
