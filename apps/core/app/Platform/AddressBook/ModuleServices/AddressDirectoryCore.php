<?php

declare(strict_types=1);

namespace App\Platform\AddressBook\ModuleServices;

use App\Platform\AddressBook\Models\Location;
use App\Platform\AddressBook\Models\PartyLocation;
use App\Platform\Modules\Contracts\AddressDirectory;
use App\Platform\Organization\Models\OrganizationParty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pelaksana {@see AddressDirectory}: membaca tempat beralamat pos dari buku alamat, tanpa menulis.
 */
final class AddressDirectoryCore implements AddressDirectory
{
    /**
     * Batas pilihan. Tempat beralamat pos satu tenant adalah alamat organisasinya sendiri — puluhan, bukan
     * ribuan — jadi batas ini hanya pagar terhadap data yang tidak wajar.
     */
    private const LIMIT = 500;

    public function postalAddresses(string $tenantId): array
    {
        return array_values($this->postal($tenantId)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Location $location): array => $this->present($location))
            ->all());
    }

    public function describe(string $tenantId, array $locationIds): array
    {
        $ids = array_values(array_unique(array_filter($locationIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }

        $found = [];
        foreach ($this->postal($tenantId)->whereKey($ids)->get() as $location) {
            $found[$location->id] = $this->present($location);
        }

        return $found;
    }

    public function primaryOfOrganization(string $tenantId, string $organizationId): ?array
    {
        $partyId = OrganizationParty::query()->where('tenant_id', $tenantId)->where('organization_id', $organizationId)->value('party_id');
        if ($partyId === null) {
            return null;
        }

        // Alamat utama lebih dulu; bila tidak ada yang ditandai utama, alamat tertua — urutan yang sama
        // dengan kop dokumen di OrganizationAddressBook::summary().
        $link = PartyLocation::query()
            ->where('party_id', $partyId)
            ->whereHas('location.postalAddress')
            ->with('location.postalAddress')
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->first();

        return $link === null ? null : $this->present($link->location);
    }

    /** @return Builder<Location> */
    private function postal(string $tenantId): Builder
    {
        return Location::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('postalAddress')
            ->with('postalAddress');
    }

    /** @return array{id: string, nama: string, alamat: string} */
    private function present(Location $location): array
    {
        return [
            'id' => $location->id,
            'nama' => $location->name,
            'alamat' => (string) $location->postalAddress?->formatted,
        ];
    }
}
