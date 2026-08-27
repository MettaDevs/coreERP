<?php

namespace App\Services\AddressHierarchy;

use App\Models\ReferenceData\AddressHierarchy\AdministrativeDivisionTimezone;
use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\District;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
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
                if ($province && $province->country_code) {
                    return $this->resolveDirectOrParent('country', $province->country_code);
                }
                break;

            case 'country':
                // Check if country has default timezone mapping
                $countryMapping = AdministrativeDivisionTimezone::where('division_type', 'country')
                    ->where('division_id', $divisionId)
                    ->where('status', 'active')
                    ->where('is_default', true)
                    ->first();
                if ($countryMapping) {
                    $country = Country::where('code', $divisionId)->first();
                    return $this->formatTimezoneData($countryMapping->timezone, $divisionId, 'country', $country?->name ?? $divisionId);
                }
                break;
        }

        // 3. Nowhere in hierarchy has timezone mapping -> return null (no guesswork)
        return null;
    }

    private function getDivisionName(string $divisionType, string $divisionId): string
    {
        return match ($divisionType) {
            'village'  => Village::where('id', $divisionId)->value('name') ?? $divisionId,
            'district' => District::where('id', $divisionId)->value('name') ?? $divisionId,
            'regency'  => Regency::where('id', $divisionId)->value('name') ?? $divisionId,
            'province' => Province::where('id', $divisionId)->value('name') ?? $divisionId,
            'country'  => Country::where('code', $divisionId)->value('name') ?? $divisionId,
            default    => $divisionId,
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
            'Asia/Jakarta', 'Asia/Pontianak'                   => 'WIB',
            'Asia/Makassar', 'Asia/Ujung_Pandang'              => 'WITA',
            'Asia/Jayapura'                                     => 'WIT',
            'Asia/Kuala_Lumpur', 'Asia/Kuching'                 => 'MYT',
            'Asia/Singapore'                                    => 'SGT',
            'Asia/Bangkok', 'Asia/Ho_Chi_Minh', 'Asia/Phnom_Penh', 'Asia/Vientiane' => 'ICT',
            'Asia/Manila'                                       => 'PHT',
            'Asia/Brunei'                                       => 'BNT',
            'Asia/Yangon'                                       => 'MMT',
            'Asia/Dili'                                         => 'TLT',
            default                                             => 'UTC',
        };

        $displayName = match ($ianaTimezone) {
            'Asia/Jakarta'      => "(UTC{$offsetString}) WIB — Jakarta",
            'Asia/Makassar'     => "(UTC{$offsetString}) WITA — Bali, Makassar",
            'Asia/Jayapura'     => "(UTC{$offsetString}) WIT — Jayapura",
            'Asia/Kuala_Lumpur' => "(UTC{$offsetString}) MYT — Kuala Lumpur",
            'Asia/Singapore'    => "(UTC{$offsetString}) SGT — Singapore",
            'Asia/Bangkok'      => "(UTC{$offsetString}) ICT — Bangkok",
            'Asia/Manila'       => "(UTC{$offsetString}) PHT — Manila",
            'Asia/Brunei'       => "(UTC{$offsetString}) BNT — Bandar Seri Begawan",
            'Asia/Yangon'       => "(UTC{$offsetString}) MMT — Yangon",
            'Asia/Dili'         => "(UTC{$offsetString}) TLT — Dili",
            default             => "(UTC{$offsetString}) {$label} — {$ianaTimezone}",
        };

        return [
            'timezone'             => $ianaTimezone,
            'offset'               => $offsetString,
            'label'                => $label,
            'display_name'         => $displayName,
            'source_division_id'   => $sourceDivisionId,
            'source_division_type' => $sourceDivisionType,
            'source_division_name' => $sourceDivisionName,
        ];
    }
}
