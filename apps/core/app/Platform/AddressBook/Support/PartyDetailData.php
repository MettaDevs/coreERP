<?php

declare(strict_types=1);

namespace App\Platform\AddressBook\Support;

use App\Platform\AddressBook\Models\ElectronicAddress;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PartyDetailData
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function location(array $data): array
    {
        if (! empty($data['country_region_code']) && is_string($data['country_region_code'])) {
            $data['country_region_code'] = strtoupper($data['country_region_code']);
        }
        if (! array_key_exists('purposes', $data) && ! empty($data['purpose'])) {
            $data['purposes'] = [$data['purpose']];
        }
        $linking = ! empty($data['location_id']);

        return Validator::make($data, [
            'location_id' => ['nullable', 'ulid'],
            'name' => [$linking ? 'nullable' : 'required', 'string', 'max:120'],
            'purposes' => ['required', 'array', 'min:1'],
            'purposes.*' => ['string', 'distinct', Rule::exists('location_purposes', 'code')],
            'is_primary' => ['sometimes', 'boolean'],
            'country_region_code' => [$linking ? 'nullable' : 'required', 'string', 'size:2', Rule::exists('country_regions', 'code')],
            'province' => ['nullable', 'string', 'max:100'], 'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'], 'village' => ['nullable', 'string', 'max:100'],
            'rt' => ['nullable', 'string', 'regex:/^[0-9]{1,3}$/D'], 'rw' => ['nullable', 'string', 'regex:/^[0-9]{1,3}$/D'],
            'street' => ['nullable', 'string', 'max:250'], 'building' => ['nullable', 'string', 'max:120'],
            'postbox' => ['nullable', 'string', 'max:60'], 'postal_code' => ['nullable', 'string', 'max:20'],
            ...PostalAddressReferences::rules(),
        ])->validate();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function contact(array $data): array
    {
        return Validator::make($data, [
            'type' => ['required', Rule::in(ElectronicAddress::TYPES)],
            'value' => ['required', 'string', 'max:250', ...(($data['type'] ?? null) === 'email' ? ['email'] : [])],
            'purpose' => ['nullable', 'string', 'max:30'], 'is_primary' => ['sometimes', 'boolean'],
            'address_id' => ['sometimes', 'nullable', 'ulid'],
        ])->validate();
    }

    /** @return array{locations: list<array<string, mixed>>, contacts: list<array<string, mixed>>} */
    public static function details(mixed $input): array
    {
        $data = Validator::make(['party_details' => $input], [
            'party_details' => ['array:locations,contacts'],
            'party_details.locations' => ['sometimes', 'array', 'list', 'max:100'],
            'party_details.locations.*' => ['array'],
            'party_details.locations.*.key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'party_details.contacts' => ['sometimes', 'array', 'list', 'max:100'],
            'party_details.contacts.*' => ['array'],
            'party_details.contacts.*.address_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ])->validate()['party_details'];
        $locations = [];
        $keys = [];
        foreach ($data['locations'] ?? [] as $index => $location) {
            try {
                $validated = self::location($location);
                $key = $location['key'] ?? null;
                if ($key !== null && $key !== '') {
                    if (isset($keys[$key])) {
                        throw ValidationException::withMessages(['key' => 'Setiap alamat baru harus memiliki penanda yang berbeda.']);
                    }
                    $keys[$key] = true;
                    $validated['key'] = $key;
                }
                $locations[] = $validated;
            } catch (ValidationException $failure) {
                self::rethrow($failure, 'party_details.locations.'.$index);
            }
        }
        $contacts = [];
        foreach ($data['contacts'] ?? [] as $index => $contact) {
            try {
                $validated = self::contact($contact);
                $key = $contact['address_key'] ?? null;
                if ($key !== null && $key !== '') {
                    if (! empty($validated['address_id'])) {
                        throw ValidationException::withMessages(['address_key' => 'Pilih satu alamat untuk kontak ini.']);
                    }
                    if (! isset($keys[$key])) {
                        throw ValidationException::withMessages(['address_key' => 'Pilih alamat yang ditambahkan pada form ini.']);
                    }
                    $validated['address_key'] = $key;
                }
                $contacts[] = $validated;
            } catch (ValidationException $failure) {
                self::rethrow($failure, 'party_details.contacts.'.$index);
            }
        }

        return ['locations' => $locations, 'contacts' => $contacts];
    }

    public static function rethrow(ValidationException $failure, string $prefix): never
    {
        $errors = [];
        foreach ($failure->errors() as $field => $messages) {
            $errors[$prefix.'.'.$field] = $messages;
        }
        throw ValidationException::withMessages($errors);
    }
}
