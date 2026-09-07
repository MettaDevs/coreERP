<?php

namespace App\Support\AddressBook;

use App\Models\ElectronicAddress;
use App\Models\Organization;
use App\Models\OrganizationParty;
use App\Models\Party;
use App\Models\PartyLocation;
use App\Models\PostalAddress;
use Illuminate\Support\Facades\DB;

/**
 * Buku alamat satu organisasi: legal entity dan operating unit adalah party, jadi
 * alamat dan kontaknya disimpan di tabel party yang sama dengan pelanggan dan pemasok.
 * Kelas ini yang menautkan organisasi ke party-nya (dibuat saat pertama dibutuhkan,
 * bukan saat organisasi dibuat, supaya organisasi lama ikut punya) dan menjaga aturan
 * "satu utama": satu lokasi utama per party, satu kontak utama per jenis.
 *
 * Identitas cetak membaca dari sini. Alamat dan telepon tidak disalin ke tabel lain;
 * mengubahnya di bagian Alamat langsung mengubah kop setiap dokumen.
 */
final class OrganizationAddressBook
{
    public function party(Organization $organization): Party
    {
        $link = OrganizationParty::query()->where('organization_id', $organization->id)->first();
        if ($link !== null) {
            $party = $link->party;
            if ($party->name !== $organization->name) {
                $party->update(['name' => $organization->name, 'search_name' => Party::searchName($organization->name)]);
            }

            return $party;
        }

        return DB::transaction(function () use ($organization): Party {
            $party = Party::create([
                'tenant_id' => $organization->tenant_id,
                'type' => 'organization',
                'name' => $organization->name,
                'search_name' => Party::searchName($organization->name),
                'status' => 'active',
            ]);
            OrganizationParty::create([
                'organization_id' => $organization->id,
                'tenant_id' => $organization->tenant_id,
                'party_id' => $party->id,
            ]);

            return $party;
        });
    }

    /** @return list<array<string, mixed>> */
    public function locations(Organization $organization): array
    {
        return array_values(PartyLocation::query()
            ->where('party_id', $this->party($organization)->id)
            ->with('postalAddress')
            ->orderByDesc('is_primary')->orderBy('created_at')
            ->get()
            ->map(fn (PartyLocation $location): array => $this->presentLocation($location))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveLocation(Organization $organization, array $data, ?string $locationId = null): array
    {
        $party = $this->party($organization);

        return DB::transaction(function () use ($party, $data, $locationId): array {
            $location = $locationId === null
                ? new PartyLocation(['tenant_id' => $party->tenant_id, 'party_id' => $party->id])
                : PartyLocation::query()->where('party_id', $party->id)->findOrFail($locationId);

            // Lokasi pertama otomatis utama; tanpa ini kop kosong sampai seseorang ingat
            // mencentang "utama".
            $hasOthers = PartyLocation::query()->where('party_id', $party->id)->whereKeyNot($location->id)->exists();
            $primary = ! $hasOthers || (bool) ($data['is_primary'] ?? false) || ($locationId !== null && $location->is_primary && ! array_key_exists('is_primary', $data));
            if ($primary) {
                PartyLocation::query()->where('party_id', $party->id)->whereKeyNot($location->id)->update(['is_primary' => false]);
            }
            $location->fill([
                'name' => $data['name'],
                'purpose' => $data['purpose'],
                'is_primary' => $primary,
            ])->save();

            $address = $location->postalAddress ?? new PostalAddress(['tenant_id' => $party->tenant_id, 'location_id' => $location->id]);
            $fields = [
                'country_region_code' => strtoupper($data['country_region_code']),
                'province' => $this->clean($data['province'] ?? null),
                'city' => $this->clean($data['city'] ?? null),
                'district' => $this->clean($data['district'] ?? null),
                'street' => $this->clean($data['street'] ?? null),
                'building' => $this->clean($data['building'] ?? null),
                'postbox' => $this->clean($data['postbox'] ?? null),
                'postal_code' => $this->clean($data['postal_code'] ?? null),
            ];
            $address->fill([...$fields, 'formatted' => PostalAddressFormatter::format($fields)])->save();

            return $this->presentLocation($location->fresh('postalAddress'));
        });
    }

    public function deleteLocation(Organization $organization, string $locationId): void
    {
        $party = $this->party($organization);
        DB::transaction(function () use ($party, $locationId): void {
            $location = PartyLocation::query()->where('party_id', $party->id)->findOrFail($locationId);
            $wasPrimary = $location->is_primary;
            $location->delete();
            if ($wasPrimary) {
                // Dokumen tidak boleh kehilangan alamat hanya karena alamat utama dihapus;
                // lokasi tertua yang tersisa naik menjadi utama.
                PartyLocation::query()->where('party_id', $party->id)->orderBy('created_at')->limit(1)->update(['is_primary' => true]);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function contacts(Organization $organization): array
    {
        return array_values(ElectronicAddress::query()
            ->where('party_id', $this->party($organization)->id)
            ->orderBy('type')->orderByDesc('is_primary')->orderBy('created_at')
            ->get()
            ->map(fn (ElectronicAddress $contact): array => $this->presentContact($contact))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveContact(Organization $organization, array $data, ?string $contactId = null): array
    {
        $party = $this->party($organization);

        return DB::transaction(function () use ($party, $data, $contactId): array {
            $contact = $contactId === null
                ? new ElectronicAddress(['tenant_id' => $party->tenant_id, 'party_id' => $party->id])
                : ElectronicAddress::query()->where('party_id', $party->id)->findOrFail($contactId);

            $hasOthersOfType = ElectronicAddress::query()->where('party_id', $party->id)->where('type', $data['type'])->whereKeyNot($contact->id)->exists();
            $primary = ! $hasOthersOfType || (bool) ($data['is_primary'] ?? false) || ($contactId !== null && $contact->is_primary && $contact->type === $data['type'] && ! array_key_exists('is_primary', $data));
            if ($primary) {
                ElectronicAddress::query()->where('party_id', $party->id)->where('type', $data['type'])->whereKeyNot($contact->id)->update(['is_primary' => false]);
            }
            $contact->fill([
                'type' => $data['type'],
                'value' => trim($data['value']),
                'purpose' => $this->clean($data['purpose'] ?? null),
                'is_primary' => $primary,
            ])->save();

            return $this->presentContact($contact->fresh());
        });
    }

    public function deleteContact(Organization $organization, string $contactId): void
    {
        $party = $this->party($organization);
        DB::transaction(function () use ($party, $contactId): void {
            $contact = ElectronicAddress::query()->where('party_id', $party->id)->findOrFail($contactId);
            $type = $contact->type;
            $wasPrimary = $contact->is_primary;
            $contact->delete();
            if ($wasPrimary) {
                ElectronicAddress::query()->where('party_id', $party->id)->where('type', $type)->orderBy('created_at')->limit(1)->update(['is_primary' => true]);
            }
        });
    }

    /**
     * Ringkasan untuk kop: alamat utama sebagai baris, dan kontak utama per jenis.
     * Dibaca tanpa membuat party, supaya sekadar mencetak tidak menulis apa pun.
     *
     * @return array{address_lines: list<string>, phone: ?string, whatsapp: ?string, fax: ?string, email: ?string, website: ?string}
     */
    public function summary(string $organizationId): array
    {
        $empty = ['address_lines' => [], 'phone' => null, 'whatsapp' => null, 'fax' => null, 'email' => null, 'website' => null];
        $partyId = OrganizationParty::query()->where('organization_id', $organizationId)->value('party_id');
        if ($partyId === null) {
            return $empty;
        }

        $location = PartyLocation::query()->where('party_id', $partyId)->with('postalAddress')
            ->orderByDesc('is_primary')->orderBy('created_at')->first();
        $address = $location?->postalAddress;
        $formatted = $address instanceof PostalAddress ? $address->formatted : '';
        $contacts = ElectronicAddress::query()->where('party_id', $partyId)
            ->orderByDesc('is_primary')->orderBy('created_at')->get()
            ->groupBy('type')->map(fn ($group) => $group->first()->value);

        return [
            'address_lines' => $formatted === '' ? [] : explode("\n", $formatted),
            'phone' => $contacts['phone'] ?? null,
            'whatsapp' => $contacts['whatsapp'] ?? null,
            'fax' => $contacts['fax'] ?? null,
            'email' => $contacts['email'] ?? null,
            'website' => $contacts['url'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentLocation(PartyLocation $location): array
    {
        $address = $location->postalAddress;

        return [
            'id' => $location->id,
            'name' => $location->name,
            'purpose' => $location->purpose,
            'is_primary' => $location->is_primary,
            'country_region_code' => $address?->country_region_code,
            'province' => $address?->province,
            'city' => $address?->city,
            'district' => $address?->district,
            'street' => $address?->street,
            'building' => $address?->building,
            'postbox' => $address?->postbox,
            'postal_code' => $address?->postal_code,
            'formatted' => $address instanceof PostalAddress ? $address->formatted : '',
        ];
    }

    /** @return array<string, mixed> */
    private function presentContact(ElectronicAddress $contact): array
    {
        return [
            'id' => $contact->id,
            'type' => $contact->type,
            'value' => $contact->value,
            'purpose' => $contact->purpose,
            'is_primary' => $contact->is_primary,
        ];
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
