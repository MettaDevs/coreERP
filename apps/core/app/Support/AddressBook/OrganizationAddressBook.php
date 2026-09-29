<?php

namespace App\Support\AddressBook;

use App\Models\ElectronicAddress;
use App\Models\Location;
use App\Models\LocationPurpose;
use App\Models\Organization;
use App\Models\OrganizationParty;
use App\Models\Party;
use App\Models\PartyLocation;
use App\Models\PartyLocationPurpose;
use App\Models\PostalAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Buku alamat satu organisasi: legal entity dan operating unit adalah party, jadi
 * alamat dan kontaknya disimpan di tabel party yang sama dengan pelanggan dan pemasok.
 * Kelas ini yang menautkan organisasi ke party-nya (dibuat saat pertama dibutuhkan,
 * bukan saat organisasi dibuat, supaya organisasi lama ikut punya) dan menjaga aturan
 * "satu utama": satu alamat utama per party, satu kontak utama per tempat per jenis.
 *
 * Bentuknya mengikuti Dynamics 365: alamat adalah tautan party ke tempat ({@see Location}),
 * tempat dapat dipakai beberapa pihak, dan kontak menempel ke tempat. Id alamat yang dipakai
 * layar dan API adalah id tautan. Melepas alamat mengarsipkan tautannya saja; tempat dan
 * alamat posnya tetap ada untuk pihak lain yang memakainya.
 *
 * Kontak yang tidak dipasang ke alamat mana pun menempel ke satu tempat tanpa alamat pos milik
 * party itu, bernama "Informasi kontak". Tempat itu tidak tampil sebagai alamat.
 *
 * Identitas cetak membaca dari sini. Alamat dan telepon tidak disalin ke tabel lain;
 * mengubahnya di bagian Alamat langsung mengubah kop setiap dokumen.
 */
final class OrganizationAddressBook
{
    private const CONTACT_LOCATION_NAME = 'Informasi kontak';

    /** @var list<string>|null */
    private ?array $purposeOrder = null;

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
        return array_values($this->addressLinks($this->party($organization)->id)
            ->with(['location.postalAddress', 'purposes'])
            ->orderByDesc('is_primary')->orderBy('created_at')
            ->get()
            ->map(fn (PartyLocation $link): array => $this->presentLocation($link))
            ->all());
    }

    /**
     * Tempat beralamat pos milik tenant, untuk dipilih sebagai alamat bersama.
     *
     * @return list<array{id: string, name: string, formatted: string}>
     */
    public function sharableLocations(string $tenantId, string $search = ''): array
    {
        return array_values(Location::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('postalAddress')
            ->with('postalAddress')
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'formatted' => (string) $location->postalAddress?->formatted,
            ])
            ->all());
    }

    /**
     * Membuat atau mengubah satu alamat. `location_id` menautkan tempat yang sudah ada alih-alih membuat
     * yang baru; alamat posnya tidak diubah dari sini. Mengubah alamat pos tempat bersama mengubahnya bagi
     * setiap pihak yang memakainya.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveLocation(Organization $organization, array $data, ?string $linkId = null): array
    {
        $party = $this->party($organization);

        return DB::transaction(function () use ($party, $data, $linkId): array {
            // Dua permintaan yang sama-sama menandai "utama" diantrikan di sini, bukan diserahkan ke index.
            Party::query()->whereKey($party->id)->lockForUpdate()->first();

            if ($linkId !== null) {
                $link = $this->addressLinks($party->id)->whereKey($linkId)->firstOrFail();
                $location = $link->location;
            } elseif (($data['location_id'] ?? null) !== null) {
                $location = Location::query()->where('tenant_id', $party->tenant_id)->whereHas('postalAddress')->whereKey($data['location_id'])->firstOrFail();
                $link = PartyLocation::query()->where('party_id', $party->id)->where('location_id', $location->id)->first()
                    ?? new PartyLocation(['tenant_id' => $party->tenant_id, 'party_id' => $party->id, 'location_id' => $location->id]);
            } else {
                $location = Location::create(['tenant_id' => $party->tenant_id, 'name' => $data['name']]);
                $link = new PartyLocation(['tenant_id' => $party->tenant_id, 'party_id' => $party->id, 'location_id' => $location->id]);
            }

            // Alamat pertama otomatis utama; tanpa ini kop kosong sampai seseorang ingat
            // mencentang "utama".
            $hasOthers = $this->addressLinks($party->id)->when($link->exists, fn (Builder $query) => $query->whereKeyNot($link->id))->exists();
            $primary = ! $hasOthers || (bool) ($data['is_primary'] ?? false) || ($link->exists && $link->is_primary && ! array_key_exists('is_primary', $data));
            if ($primary) {
                PartyLocation::query()->where('party_id', $party->id)
                    ->when($link->exists, fn (Builder $query) => $query->whereKeyNot($link->id))
                    ->update(['is_primary' => false]);
            }
            $link->fill(['is_primary' => $primary])->save();

            if (($data['location_id'] ?? null) === null || $linkId !== null) {
                $location->fill(['name' => $data['name']])->save();
                $this->savePostalAddress($location, $data);
            }
            $this->syncPurposes($link, $data['purposes']);

            return $this->presentLocation($link->fresh(['location.postalAddress', 'purposes']));
        });
    }

    /**
     * Melepas alamat dari organisasi: tautannya diarsipkan, tempatnya tidak. Pihak lain yang memakai tempat
     * yang sama tidak kehilangan apa pun.
     */
    public function deleteLocation(Organization $organization, string $linkId): void
    {
        $party = $this->party($organization);
        DB::transaction(function () use ($party, $linkId): void {
            Party::query()->whereKey($party->id)->lockForUpdate()->first();

            $link = $this->addressLinks($party->id)->whereKey($linkId)->firstOrFail();
            $wasPrimary = $link->is_primary;
            $link->fill(['is_primary' => false])->save();
            $link->delete();
            if ($wasPrimary) {
                // Dokumen tidak boleh kehilangan alamat hanya karena alamat utama dilepas;
                // alamat tertua yang tersisa naik menjadi utama.
                $this->addressLinks($party->id)->orderBy('created_at')->limit(1)->update(['is_primary' => true]);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function contacts(Organization $organization): array
    {
        $party = $this->party($organization);
        $links = PartyLocation::query()->where('party_id', $party->id)->with('location.postalAddress')->get()->keyBy('location_id');

        return array_values(ElectronicAddress::query()
            ->whereIn('location_id', $links->keys())
            ->orderBy('type')->orderByDesc('is_primary')->orderBy('created_at')
            ->get()
            ->map(fn (ElectronicAddress $contact): array => $this->presentContact($contact, $links->get($contact->location_id)))
            ->all());
    }

    /**
     * `address_id` memasang kontak ke salah satu alamat organisasi. Kosong berarti kontak umum: bila
     * organisasi punya alamat utama, kontak itu menempel ke sana, supaya kop tetap membacanya; bila belum,
     * ke tempat "Informasi kontak".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveContact(Organization $organization, array $data, ?string $contactId = null): array
    {
        $party = $this->party($organization);

        return DB::transaction(function () use ($party, $data, $contactId): array {
            Party::query()->whereKey($party->id)->lockForUpdate()->first();

            $contact = $contactId === null ? null : $this->partyContacts($party->id)->whereKey($contactId)->firstOrFail();
            $locationId = match (true) {
                ($data['address_id'] ?? null) !== null => $this->addressLinks($party->id)->whereKey($data['address_id'])->firstOrFail()->location_id,
                $contact !== null && ! array_key_exists('address_id', $data) => $contact->location_id,
                default => $this->defaultContactLocation($party),
            };
            $contact ??= new ElectronicAddress(['tenant_id' => $party->tenant_id]);

            $siblings = ElectronicAddress::query()->where('location_id', $locationId)->where('type', $data['type'])
                ->when($contact->exists, fn (Builder $query) => $query->whereKeyNot($contact->id));
            $keepsPrimary = $contact->exists && $contact->is_primary && $contact->type === $data['type']
                && $contact->location_id === $locationId && ! array_key_exists('is_primary', $data);
            $primary = ! $siblings->exists() || (bool) ($data['is_primary'] ?? false) || $keepsPrimary;
            if ($primary) {
                $siblings->update(['is_primary' => false]);
            }
            $contact->fill([
                'location_id' => $locationId,
                'type' => $data['type'],
                'value' => trim($data['value']),
                'purpose' => $this->clean($data['purpose'] ?? null),
                'is_primary' => $primary,
            ])->save();

            $link = PartyLocation::query()->where('party_id', $party->id)->where('location_id', $locationId)->with('location.postalAddress')->first();

            return $this->presentContact($contact->fresh(), $link);
        });
    }

    public function deleteContact(Organization $organization, string $contactId): void
    {
        $party = $this->party($organization);
        DB::transaction(function () use ($party, $contactId): void {
            Party::query()->whereKey($party->id)->lockForUpdate()->first();

            $contact = $this->partyContacts($party->id)->whereKey($contactId)->firstOrFail();
            $wasPrimary = $contact->is_primary;
            $contact->fill(['is_primary' => false])->save();
            $contact->delete();
            if ($wasPrimary) {
                ElectronicAddress::query()->where('location_id', $contact->location_id)->where('type', $contact->type)
                    ->orderBy('created_at')->limit(1)->update(['is_primary' => true]);
            }
        });
    }

    /**
     * Ringkasan untuk kop: alamat utama sebagai baris, dan kontak utama per jenis.
     * Kontak dibaca dari tempat alamat utama lebih dulu, lalu dari tempat party yang lain.
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

        $links = PartyLocation::query()->where('party_id', $partyId)->with('location.postalAddress')
            ->orderByDesc('is_primary')->orderBy('created_at')->get();
        $address = $links->first(fn (PartyLocation $link): bool => $link->location->postalAddress !== null)?->location->postalAddress;
        $formatted = $address instanceof PostalAddress ? $address->formatted : '';
        $order = $links->pluck('location_id')->flip();
        $contacts = ElectronicAddress::query()->whereIn('location_id', $links->pluck('location_id'))->get()
            ->sortBy(fn (ElectronicAddress $contact): array => [$order[$contact->location_id], $contact->is_primary ? 0 : 1, (string) $contact->created_at])
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

    /**
     * Tautan party yang tampil sebagai alamat: tempatnya beralamat pos.
     *
     * @return Builder<PartyLocation>
     */
    private function addressLinks(string $partyId): Builder
    {
        return PartyLocation::query()->where('party_id', $partyId)->whereHas('location.postalAddress');
    }

    /** @return Builder<ElectronicAddress> */
    private function partyContacts(string $partyId): Builder
    {
        return ElectronicAddress::query()->whereIn('location_id', PartyLocation::query()->where('party_id', $partyId)->select('location_id'));
    }

    private function defaultContactLocation(Party $party): string
    {
        $primary = $this->addressLinks($party->id)->where('is_primary', true)->value('location_id');
        if ($primary !== null) {
            return $primary;
        }

        $existing = PartyLocation::query()->where('party_id', $party->id)->whereDoesntHave('location.postalAddress')
            ->orderBy('created_at')->value('location_id');
        if ($existing !== null) {
            return $existing;
        }

        $location = Location::create(['tenant_id' => $party->tenant_id, 'name' => self::CONTACT_LOCATION_NAME]);
        PartyLocation::create(['tenant_id' => $party->tenant_id, 'party_id' => $party->id, 'location_id' => $location->id, 'is_primary' => false]);

        return $location->id;
    }

    /** @param  array<string, mixed>  $data */
    private function savePostalAddress(Location $location, array $data): void
    {
        $address = $location->postalAddress ?? new PostalAddress(['tenant_id' => $location->tenant_id, 'location_id' => $location->id]);
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
    }

    /** @param  list<string>  $purposes */
    private function syncPurposes(PartyLocation $link, array $purposes): void
    {
        PartyLocationPurpose::query()->where('party_location_id', $link->id)->whereNotIn('purpose_code', $purposes)->delete();
        $existing = PartyLocationPurpose::query()->where('party_location_id', $link->id)->pluck('purpose_code')->all();
        foreach (array_diff($purposes, $existing) as $code) {
            PartyLocationPurpose::create(['tenant_id' => $link->tenant_id, 'party_location_id' => $link->id, 'purpose_code' => $code]);
        }
    }

    /** @return list<string> */
    private function purposeOrder(): array
    {
        return $this->purposeOrder ??= LocationPurpose::codes();
    }

    /** @return array<string, mixed> */
    private function presentLocation(PartyLocation $link): array
    {
        $address = $link->location->postalAddress;

        return [
            'id' => $link->id,
            'location_id' => $link->location_id,
            'name' => $link->location->name,
            'purposes' => array_values(array_intersect($this->purposeOrder(), $link->purposes->pluck('purpose_code')->all())),
            'is_primary' => $link->is_primary,
            // Pihak lain yang memakai tempat yang sama; mengubah alamat ini mengubahnya bagi mereka juga.
            'shared_with' => PartyLocation::query()
                ->where('location_id', $link->location_id)->where('party_id', '<>', $link->party_id)->count(),
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
    private function presentContact(ElectronicAddress $contact, ?PartyLocation $link): array
    {
        $isAddress = $link !== null && $link->location->postalAddress !== null;

        return [
            'id' => $contact->id,
            'type' => $contact->type,
            'value' => $contact->value,
            'purpose' => $contact->purpose,
            'is_primary' => $contact->is_primary,
            'address_id' => $isAddress ? $link->id : null,
            'address_name' => $isAddress ? $link->location->name : null,
        ];
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
