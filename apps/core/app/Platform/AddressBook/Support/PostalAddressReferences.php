<?php

namespace App\Platform\AddressBook\Support;

use App\Platform\AddressBook\Models\PostalAddress;
use App\Platform\Geography\Models\AddressHierarchy\District;
use App\Platform\Geography\Models\AddressHierarchy\PostalCode;
use App\Platform\Geography\Models\AddressHierarchy\Province;
use App\Platform\Geography\Models\AddressHierarchy\Regency;
use App\Platform\Geography\Models\AddressHierarchy\Village;
use Illuminate\Validation\ValidationException;

final class PostalAddressReferences
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return array_fill_keys(['province_id', 'regency_id', 'district_id', 'village_id', 'postal_code_id'], ['sometimes', 'nullable', 'ulid']);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, ?PostalAddress $existing): array
    {
        $country = $data['country_region_code'];
        $parentId = $country;
        foreach ([
            ['province', 'province', Province::class, 'country_code'],
            ['regency', 'city', Regency::class, 'province_id'],
            ['district', 'district', District::class, 'regency_id'],
            ['village', 'village', Village::class, 'district_id'],
        ] as [$reference, $text, $model, $parentField]) {
            $field = $reference.'_id';
            $id = array_key_exists($field, $data) ? $data[$field] : $existing?->getAttribute($field);
            if ($id !== null && $id !== '') {
                $record = $model::query()->whereNull('tenant_id')->whereKey($id)->where($parentField, $parentId)->first();
                if ($record === null || $parentId === null) {
                    throw ValidationException::withMessages([$field => 'Pilih wilayah yang sesuai dengan pilihan sebelumnya.']);
                }
                $data[$field] = $record->id;
                $data[$text] = $record->name;
            } else {
                $data[$field] = null;
            }
            $parentId = $data[$field];
        }
        $id = array_key_exists('postal_code_id', $data) ? $data['postal_code_id'] : $existing?->getAttribute('postal_code_id');
        if ($id !== null && $id !== '') {
            $postal = PostalCode::query()->whereKey($id)->whereNull('tenant_id')->where('country_code', $country)->where('village_id', $parentId)->first();
            if ($postal === null || $parentId === null) {
                throw ValidationException::withMessages(['postal_code_id' => 'Pilih kode pos untuk kelurahan atau desa yang dipilih.']);
            }
            $data['postal_code_id'] = $postal->id;
            $data['postal_code'] = $postal->postal_code;
        } else {
            $data['postal_code_id'] = null;
        }

        return $data;
    }
}
