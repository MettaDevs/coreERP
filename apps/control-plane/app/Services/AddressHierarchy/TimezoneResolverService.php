<?php

namespace App\Services\AddressHierarchy;

use App\Models\ReferenceData\AddressHierarchy\AdministrativeDivisionTimezone;
use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\District;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use App\Models\ReferenceData\AddressHierarchy\TimeZone;
use App\Models\ReferenceData\AddressHierarchy\Village;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;

class TimezoneResolverService
{
    /**
     * Resolve timezone for any administrative division ID by hierarchy traversal.
     * Never uses string/name matching — strictly relies on division ID and parent foreign keys.
     */
    public function resolve(string $divisionType, string $divisionId): ?array
    {
        $cacheKey = "timezone:division:{$divisionType}:{$divisionId}";

        return Cache::remember($cacheKey, 3600, function () use ($divisionType, $divisionId) {
            return $this->resolveDirectOrParent($divisionType, $divisionId);
        });
    }

    private function resolveDirectOrParent(string $divisionType, string $divisionId): ?array
    {
        // 1. Check direct mapping in ref_administrative_division_timezones
        $mapping = AdministrativeDivisionTimezone::where('division_type', $divisionType)
            ->where('division_id', $divisionId)
            ->where('status', 'active')
            ->where('is_default', true)
            ->first();

        if ($mapping) {
            $divisionName = $this->getDivisionName($divisionType, $divisionId);

            return $this->formatTimezoneData($mapping->timezone, $divisionId, $divisionType, $divisionName);
        }

        // 2. Hierarchical fallback traversal
        switch ($divisionType) {
            case 'village':
                $village = Village::find($divisionId);
                if ($village && $village->district_id) {
                    return $this->resolveDirectOrParent('district', $village->district_id);
                }
                break;

            case 'district':
                $district = District::find($divisionId);
                if ($district && $district->regency_id) {
                    return $this->resolveDirectOrParent('regency', $district->regency_id);
                }
                break;

            case 'regency':
                $regency = Regency::find($divisionId);
                if ($regency && $regency->province_id) {
                    return $this->resolveDirectOrParent('province', $regency->province_id);
                }
                break;

            case 'province':
                $province = Province::find($divisionId);
                if ($province) {
                    if (! empty($province->timezone)) {
                        return $this->formatTimezoneData($province->timezone, $province->id, 'province', $province->name);
                    }
                    $inferredTz = $this->inferProvinceTimezone($province->country_code, $province->code, $province->name);
                    if ($inferredTz) {
                        return $this->formatTimezoneData($inferredTz, $province->id, 'province', $province->name);
                    }
                    if ($province->country_code) {
                        return $this->resolveDirectOrParent('country', $province->country_code);
                    }
                }
                break;

            case 'country':
                // 1. Check time_zones table for default timezone for this country
                $tzRecord = TimeZone::where('country_code', $divisionId)
                    ->where('active', true)
                    ->orderByDesc('is_default')
                    ->first();
                if ($tzRecord) {
                    $country = Country::where('code', $divisionId)->first();

                    return $this->formatTimezoneData($tzRecord->iana_name, $divisionId, 'country', $country?->name ?? $divisionId);
                }

                // 2. Check ref_administrative_division_timezones
                $countryMapping = AdministrativeDivisionTimezone::where('division_type', 'country')
                    ->where('division_id', $divisionId)
                    ->where('status', 'active')
                    ->where('is_default', true)
                    ->first();
                if ($countryMapping) {
                    $country = Country::where('code', $divisionId)->first();

                    return $this->formatTimezoneData($countryMapping->timezone, $divisionId, 'country', $country?->name ?? $divisionId);
                }
                $country = Country::where('code', $divisionId)->first();
                if ($country && ! empty($country->timezone)) {
                    return $this->formatTimezoneData($country->timezone, $divisionId, 'country', $country->name);
                }
                $inferredCountryTz = $this->inferCountryTimezone($divisionId);
                if ($inferredCountryTz) {
                    return $this->formatTimezoneData($inferredCountryTz, $divisionId, 'country', $country?->name ?? $divisionId);
                }
                break;
        }

        // 3. Nowhere in hierarchy has timezone mapping -> return null (no guesswork)
        return null;
    }

    public function inferProvinceTimezone(string $countryCode, ?string $code, ?string $name): ?string
    {
        if ($countryCode === 'MY') {
            return 'Asia/Kuala_Lumpur';
        }
        if ($countryCode === 'SG') {
            return 'Asia/Singapore';
        }
        if ($countryCode === 'TH') {
            return 'Asia/Bangkok';
        }
        if ($countryCode !== 'ID') {
            return null;
        }

        $raw = trim(($code ?? '').' '.($name ?? ''));
        $upper = strtoupper($raw);
        $digits = preg_replace('/\D/', '', $raw);

        // WIT (UTC+09:00): Maluku (81, 82), Papua (91..96)
        if (in_array($digits, ['81', '82', '91', '92', '93', '94', '95', '96'])
            || in_array($code, ['81', '82', '91', '92', '93', '94', '95', '96'])
            || str_contains($upper, 'PAPUA')
            || str_contains($upper, 'MALUKU')
            || str_contains($upper, 'JAYAPURA')
            || str_contains($upper, 'SORONG')
            || str_contains($upper, 'MANOKWARI')
            || str_contains($upper, 'MERAUKE')
            || str_contains($upper, 'TIMIKA')
            || str_contains($upper, 'AMBON')
            || str_contains($upper, 'TERNATE')) {
            return 'Asia/Jayapura';
        }

        // WITA (UTC+08:00): Bali (51), NTB (52), NTT (53), Kalsel (63), Kaltim (64), Kaltara (65), Sulawesi (71..76)
        if (in_array($digits, ['51', '52', '53', '63', '64', '65', '71', '72', '73', '74', '75', '76'])
            || in_array($code, ['51', '52', '53', '63', '64', '65', '71', '72', '73', '74', '75', '76'])
            || str_contains($upper, 'BALI')
            || str_contains($upper, 'DENPASAR')
            || str_contains($upper, 'BADUNG')
            || str_contains($upper, 'BULELENG')
            || str_contains($upper, 'GIANYAR')
            || str_contains($upper, 'TABANAN')
            || str_contains($upper, 'NUSA TENGGARA')
            || str_contains($upper, 'NTB')
            || str_contains($upper, 'NTT')
            || str_contains($upper, 'LOMBOK')
            || str_contains($upper, 'MATARAM')
            || str_contains($upper, 'KUPANG')
            || str_contains($upper, 'FLORES')
            || str_contains($upper, 'SULAWESI')
            || str_contains($upper, 'SULUT')
            || str_contains($upper, 'SULTENG')
            || str_contains($upper, 'SULSEL')
            || str_contains($upper, 'SULTRA')
            || str_contains($upper, 'SULBAR')
            || str_contains($upper, 'GORONTALO')
            || str_contains($upper, 'MAKASSAR')
            || str_contains($upper, 'MANADO')
            || str_contains($upper, 'PALU')
            || str_contains($upper, 'KENDARI')
            || str_contains($upper, 'MAMUJU')
            || str_contains($upper, 'KALIMANTAN SELATAN')
            || str_contains($upper, 'KALSEL')
            || str_contains($upper, 'BANJARMASIN')
            || str_contains($upper, 'KALIMANTAN TIMUR')
            || str_contains($upper, 'KALTIM')
            || str_contains($upper, 'BALIKPAPAN')
            || str_contains($upper, 'SAMARINDA')
            || str_contains($upper, 'KALIMANTAN UTARA')
            || str_contains($upper, 'KALTARA')
            || str_contains($upper, 'TARAKAN')) {
            return 'Asia/Makassar';
        }

        // WIB (UTC+07:00): Java, Sumatra, West/Central Kalimantan
        if (! empty($code) || ! empty($name)) {
            return 'Asia/Jakarta';
        }

        return null;
    }

    public function inferCountryTimezone(string $countryCode): ?string
    {
        $map = [
            'AD' => 'Europe/Andorra', 'AE' => 'Asia/Dubai', 'AF' => 'Asia/Kabul', 'AG' => 'America/Antigua',
            'AI' => 'America/Anguilla', 'AL' => 'Europe/Tirane', 'AM' => 'Asia/Yerevan', 'AO' => 'Africa/Luanda',
            'AQ' => 'Antarctica/McMurdo', 'AR' => 'America/Argentina/Buenos_Aires', 'AS' => 'Pacific/Pago_Pago',
            'AT' => 'Europe/Vienna', 'AU' => 'Australia/Sydney', 'AW' => 'America/Aruba', 'AX' => 'Europe/Mariehamn',
            'AZ' => 'Asia/Baku', 'BA' => 'Europe/Sarajevo', 'BB' => 'America/Barbados', 'BD' => 'Asia/Dhaka',
            'BE' => 'Europe/Brussels', 'BF' => 'Africa/Ouagadougou', 'BG' => 'Europe/Sofia', 'BH' => 'Asia/Bahrain',
            'BI' => 'Africa/Bujumbura', 'BJ' => 'Africa/Porto-Novo', 'BL' => 'America/St_Barthelemy',
            'BM' => 'Atlantic/Bermuda', 'BN' => 'Asia/Brunei', 'BO' => 'America/La_Paz', 'BQ' => 'America/Kralendijk',
            'BR' => 'America/Sao_Paulo', 'BS' => 'America/Nassau', 'BT' => 'Asia/Thimphu', 'BV' => 'UTC',
            'BW' => 'Africa/Gaborone', 'BY' => 'Europe/Minsk', 'BZ' => 'America/Belize', 'CA' => 'America/Toronto',
            'CC' => 'Indian/Cocos', 'CD' => 'Africa/Kinshasa', 'CF' => 'Africa/Bangui', 'CG' => 'Africa/Brazzaville',
            'CH' => 'Europe/Zurich', 'CI' => 'Africa/Abidjan', 'CK' => 'Pacific/Rarotonga', 'CL' => 'America/Santiago',
            'CM' => 'Africa/Douala', 'CN' => 'Asia/Shanghai', 'CO' => 'America/Bogota', 'CR' => 'America/Costa_Rica',
            'CU' => 'America/Havana', 'CV' => 'Atlantic/Cape_Verde', 'CW' => 'America/Curacao', 'CX' => 'Indian/Christmas',
            'CY' => 'Asia/Nicosia', 'CZ' => 'Europe/Prague', 'DE' => 'Europe/Berlin', 'DJ' => 'Africa/Djibouti',
            'DK' => 'Europe/Copenhagen', 'DM' => 'America/Dominica', 'DO' => 'America/Santo_Domingo', 'DZ' => 'Africa/Algiers',
            'EC' => 'America/Guayaquil', 'EE' => 'Europe/Tallinn', 'EG' => 'Africa/Cairo', 'EH' => 'Africa/El_Aaiun',
            'ER' => 'Africa/Asmara', 'ES' => 'Europe/Madrid', 'ET' => 'Africa/Addis_Ababa', 'FI' => 'Europe/Helsinki',
            'FJ' => 'Pacific/Fiji', 'FK' => 'Atlantic/Stanley', 'FM' => 'Pacific/Pohnpei', 'FO' => 'Atlantic/Faroe',
            'FR' => 'Europe/Paris', 'GA' => 'Africa/Libreville', 'GB' => 'Europe/London', 'GD' => 'America/Grenada',
            'GE' => 'Asia/Tbilisi', 'GF' => 'America/Cayenne', 'GG' => 'Europe/Guernsey', 'GH' => 'Africa/Accra',
            'GI' => 'Europe/Gibraltar', 'GL' => 'America/Nuuk', 'GM' => 'Africa/Banjul', 'GN' => 'Africa/Conakry',
            'GP' => 'America/Guadeloupe', 'GQ' => 'Africa/Malabo', 'GR' => 'Europe/Athens', 'GS' => 'Atlantic/South_Georgia',
            'GT' => 'America/Guatemala', 'GU' => 'Pacific/Guam', 'GW' => 'Africa/Bissau', 'GY' => 'America/Guyana',
            'HK' => 'Asia/Hong_Kong', 'HM' => 'UTC', 'HN' => 'America/Tegucigalpa', 'HR' => 'Europe/Zagreb',
            'HT' => 'America/Port-au-Prince', 'HU' => 'Europe/Budapest', 'ID' => 'Asia/Jakarta', 'IE' => 'Europe/Dublin',
            'IL' => 'Asia/Jerusalem', 'IM' => 'Europe/Isle_of_Man', 'IN' => 'Asia/Kolkata', 'IO' => 'Indian/Chagos',
            'IQ' => 'Asia/Baghdad', 'IR' => 'Asia/Tehran', 'IS' => 'Atlantic/Reykjavik', 'IT' => 'Europe/Rome',
            'JE' => 'Europe/Jersey', 'JM' => 'America/Jamaica', 'JO' => 'Asia/Amman', 'JP' => 'Asia/Tokyo',
            'KE' => 'Africa/Nairobi', 'KG' => 'Asia/Bishkek', 'KH' => 'Asia/Phnom_Penh', 'KI' => 'Pacific/Tarawa',
            'KM' => 'Indian/Comoro', 'KN' => 'America/St_Kitts', 'KP' => 'Asia/Pyongyang', 'KR' => 'Asia/Seoul',
            'KW' => 'Asia/Kuwait', 'KY' => 'America/Cayman', 'KZ' => 'Asia/Almaty', 'LA' => 'Asia/Vientiane',
            'LB' => 'Asia/Beirut', 'LC' => 'America/St_Lucia', 'LI' => 'Europe/Vaduz', 'LK' => 'Asia/Colombo',
            'LR' => 'Africa/Monrovia', 'LS' => 'Africa/Maseru', 'LT' => 'Europe/Vilnius', 'LU' => 'Europe/Luxembourg',
            'LV' => 'Europe/Riga', 'LY' => 'Africa/Tripoli', 'MA' => 'Africa/Casablanca', 'MC' => 'Europe/Monaco',
            'MD' => 'Europe/Chisinau', 'ME' => 'Europe/Podgorica', 'MF' => 'America/Marigot', 'MG' => 'Indian/Antananarivo',
            'MH' => 'Pacific/Majuro', 'MK' => 'Europe/Skopje', 'ML' => 'Africa/Bamako', 'MM' => 'Asia/Yangon',
            'MN' => 'Asia/Ulaanbaatar', 'MO' => 'Asia/Macau', 'MP' => 'Pacific/Saipan', 'MQ' => 'America/Martinique',
            'MR' => 'Africa/Nouakchott', 'MS' => 'America/Montserrat', 'MT' => 'Europe/Malta', 'MU' => 'Indian/Mauritius',
            'MV' => 'Indian/Maldives', 'MW' => 'Africa/Blantyre', 'MX' => 'America/Mexico_City', 'MY' => 'Asia/Kuala_Lumpur',
            'MZ' => 'Africa/Maputo', 'NA' => 'Africa/Windhoek', 'NC' => 'Pacific/Noumea', 'NE' => 'Africa/Niamey',
            'NF' => 'Pacific/Norfolk', 'NG' => 'Africa/Lagos', 'NI' => 'America/Managua', 'NL' => 'Europe/Amsterdam',
            'NO' => 'Europe/Oslo', 'NP' => 'Asia/Kathmandu', 'NR' => 'Pacific/Nauru', 'NU' => 'Pacific/Niue',
            'NZ' => 'Pacific/Auckland', 'OM' => 'Asia/Muscat', 'PA' => 'America/Panama', 'PE' => 'America/Lima',
            'PF' => 'Pacific/Tahiti', 'PG' => 'Pacific/Port_Moresby', 'PH' => 'Asia/Manila', 'PK' => 'Asia/Karachi',
            'PL' => 'Europe/Warsaw', 'PM' => 'America/Miquelon', 'PN' => 'Pacific/Pitcairn', 'PR' => 'America/Puerto_Rico',
            'PS' => 'Asia/Gaza', 'PT' => 'Europe/Lisbon', 'PW' => 'Pacific/Palau', 'PY' => 'America/Asuncion',
            'QA' => 'Asia/Qatar', 'RE' => 'Indian/Reunion', 'RO' => 'Europe/Bucharest', 'RS' => 'Europe/Belgrade',
            'RU' => 'Europe/Moscow', 'RW' => 'Africa/Kigali', 'SA' => 'Asia/Riyadh', 'SB' => 'Pacific/Guadalcanal',
            'SC' => 'Indian/Mahe', 'SD' => 'Africa/Khartoum', 'SE' => 'Europe/Stockholm', 'SG' => 'Asia/Singapore',
            'SH' => 'Atlantic/St_Helena', 'SI' => 'Europe/Ljubljana', 'SJ' => 'Arctic/Longyearbyen', 'SK' => 'Europe/Bratislava',
            'SL' => 'Africa/Freetown', 'SM' => 'Europe/San_Marino', 'SN' => 'Africa/Dakar', 'SO' => 'Africa/Mogadishu',
            'SR' => 'America/Paramaribo', 'SS' => 'Africa/Juba', 'ST' => 'Africa/Sao_Tome', 'SV' => 'America/El_Salvador',
            'SX' => 'America/Lower_Princes', 'SY' => 'Asia/Damascus', 'SZ' => 'Africa/Mbabane', 'TC' => 'America/Grand_Turk',
            'TD' => 'Africa/Ndjamena', 'TF' => 'Indian/Kerguelen', 'TG' => 'Africa/Lome', 'TH' => 'Asia/Bangkok',
            'TJ' => 'Asia/Dushanbe', 'TK' => 'Pacific/Fakaofo', 'TL' => 'Asia/Dili', 'TM' => 'Asia/Ashgabat',
            'TN' => 'Africa/Tunis', 'TO' => 'Pacific/Tongatapu', 'TR' => 'Europe/Istanbul', 'TT' => 'America/Port_of_Spain',
            'TV' => 'Pacific/Funafuti', 'TW' => 'Asia/Taipei', 'TZ' => 'Africa/Dar_es_Salaam', 'UA' => 'Europe/Kyiv',
            'UG' => 'Africa/Kampala', 'UM' => 'Pacific/Wake', 'US' => 'America/New_York', 'UY' => 'America/Montevideo',
            'UZ' => 'Asia/Tashkent', 'VA' => 'Europe/Vatican', 'VC' => 'America/St_Vincent', 'VE' => 'America/Caracas',
            'VG' => 'America/Tortola', 'VI' => 'America/St_Thomas', 'VN' => 'Asia/Ho_Chi_Minh', 'VU' => 'Pacific/Efate',
            'WF' => 'Pacific/Wallis', 'WS' => 'Pacific/Apia', 'YE' => 'Asia/Aden', 'YT' => 'Indian/Mayotte',
            'ZA' => 'Africa/Johannesburg', 'ZM' => 'Africa/Lusaka', 'ZW' => 'Africa/Harare',
        ];

        return $map[strtoupper($countryCode)] ?? null;
    }

    private function getDivisionName(string $divisionType, string $divisionId): string
    {
        return match ($divisionType) {
            'village' => Village::where('id', $divisionId)->value('name') ?? $divisionId,
            'district' => District::where('id', $divisionId)->value('name') ?? $divisionId,
            'regency' => Regency::where('id', $divisionId)->value('name') ?? $divisionId,
            'province' => Province::where('id', $divisionId)->value('name') ?? $divisionId,
            'country' => Country::where('code', $divisionId)->value('name') ?? $divisionId,
            default => $divisionId,
        };
    }

    /**
     * Format IANA timezone into structured display metadata with dynamic UTC offset
     */
    public function formatTimezoneData(string $ianaTimezone, string $sourceDivisionId, string $sourceDivisionType, string $sourceDivisionName): array
    {
        try {
            $tz = new DateTimeZone($ianaTimezone);
            $now = new DateTime('now', $tz);
            $offsetMinutes = $tz->getOffset($now) / 60;
            $hours = intdiv($offsetMinutes, 60);
            $minutes = abs($offsetMinutes % 60);
            $offsetSign = $hours >= 0 ? '+' : '-';
            $offsetString = sprintf('%s%02d:%02d', $offsetSign, abs($hours), $minutes);
        } catch (\Throwable) {
            $offsetString = '+07:00';
        }

        $label = match ($ianaTimezone) {
            'Asia/Jakarta', 'Asia/Pontianak' => 'WIB',
            'Asia/Makassar', 'Asia/Ujung_Pandang' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            'Asia/Kuala_Lumpur', 'Asia/Kuching' => 'MYT',
            'Asia/Singapore' => 'SGT',
            'Asia/Bangkok', 'Asia/Ho_Chi_Minh', 'Asia/Phnom_Penh', 'Asia/Vientiane' => 'ICT',
            'Asia/Manila' => 'PHT',
            'Asia/Brunei' => 'BNT',
            'Asia/Yangon' => 'MMT',
            'Asia/Dili' => 'TLT',
            default => 'UTC',
        };

        $displayName = match ($ianaTimezone) {
            'Asia/Jakarta' => "(UTC{$offsetString}) WIB — Jakarta",
            'Asia/Makassar' => "(UTC{$offsetString}) WITA — Bali, Makassar",
            'Asia/Jayapura' => "(UTC{$offsetString}) WIT — Jayapura",
            'Asia/Kuala_Lumpur' => "(UTC{$offsetString}) MYT — Kuala Lumpur",
            'Asia/Singapore' => "(UTC{$offsetString}) SGT — Singapore",
            'Asia/Bangkok' => "(UTC{$offsetString}) ICT — Bangkok",
            'Asia/Manila' => "(UTC{$offsetString}) PHT — Manila",
            'Asia/Brunei' => "(UTC{$offsetString}) BNT — Bandar Seri Begawan",
            'Asia/Yangon' => "(UTC{$offsetString}) MMT — Yangon",
            'Asia/Dili' => "(UTC{$offsetString}) TLT — Dili",
            default => "(UTC{$offsetString}) {$label} — {$ianaTimezone}",
        };

        return [
            'timezone' => $ianaTimezone,
            'offset' => $offsetString,
            'label' => $label,
            'display_name' => $displayName,
            'source_division_id' => $sourceDivisionId,
            'source_division_type' => $sourceDivisionType,
            'source_division_name' => $sourceDivisionName,
        ];
    }
}
