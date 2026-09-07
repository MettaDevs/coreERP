<?php

namespace App\Support\AddressBook;

use App\Models\CountryRegion;

/**
 * Bentuk tercetak alamat pos, disusun sekali saat disimpan ke kolom `formatted`.
 *
 * Urutannya mengikuti kebiasaan alamat Indonesia: jalan dan gedung, kelurahan atau
 * kecamatan, lalu kota dengan provinsi dan kode pos. Nama negara hanya ditulis untuk
 * alamat di luar Indonesia; kop surat dalam negeri tidak pernah mencantumkannya.
 */
final class PostalAddressFormatter
{
    /** @param array<string, ?string> $fields */
    public static function format(array $fields): string
    {
        $lines = [];
        $street = implode(', ', array_filter([$fields['street'] ?? null, $fields['building'] ?? null]));
        if ($street !== '') {
            $lines[] = $street;
        }
        if (($fields['postbox'] ?? null) !== null) {
            $lines[] = 'PO Box '.$fields['postbox'];
        }
        if (($fields['district'] ?? null) !== null) {
            $lines[] = $fields['district'];
        }
        $cityProvince = implode(', ', array_filter([$fields['city'] ?? null, $fields['province'] ?? null]));
        $city = trim(implode(' ', array_filter([$cityProvince, $fields['postal_code'] ?? null])));
        if ($city !== '') {
            $lines[] = $city;
        }
        $country = strtoupper((string) ($fields['country_region_code'] ?? ''));
        if ($country !== '' && $country !== 'ID') {
            $lines[] = CountryRegion::query()->whereKey($country)->value('name') ?? $country;
        }

        return implode("\n", $lines);
    }
}
