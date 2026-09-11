<?php

namespace Database\Seeders;

use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\TimeZone;
use DateTime;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TimeZonesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seeds IANA Timezones per country, supporting single and multiple timezones per country.
     */
    public function run(): void
    {
        // 1. Multi-timezone countries configuration
        $multiTimezoneCountries = [
            'ID' => [
                ['iana' => 'Asia/Jakarta', 'default' => true],
                ['iana' => 'Asia/Makassar', 'default' => false],
                ['iana' => 'Asia/Jayapura', 'default' => false],
            ],
            'US' => [
                ['iana' => 'America/New_York', 'default' => true],
                ['iana' => 'America/Chicago', 'default' => false],
                ['iana' => 'America/Denver', 'default' => false],
                ['iana' => 'America/Los_Angeles', 'default' => false],
                ['iana' => 'America/Anchorage', 'default' => false],
                ['iana' => 'Pacific/Honolulu', 'default' => false],
                ['iana' => 'America/Phoenix', 'default' => false],
                ['iana' => 'America/Detroit', 'default' => false],
                ['iana' => 'America/Indiana/Indianapolis', 'default' => false],
                ['iana' => 'America/Boise', 'default' => false],
                ['iana' => 'America/Puerto_Rico', 'default' => false],
                ['iana' => 'Pacific/Guam', 'default' => false],
            ],
            'AU' => [
                ['iana' => 'Australia/Sydney', 'default' => true],
                ['iana' => 'Australia/Melbourne', 'default' => false],
                ['iana' => 'Australia/Brisbane', 'default' => false],
                ['iana' => 'Australia/Adelaide', 'default' => false],
                ['iana' => 'Australia/Darwin', 'default' => false],
                ['iana' => 'Australia/Perth', 'default' => false],
                ['iana' => 'Australia/Hobart', 'default' => false],
            ],
            'CA' => [
                ['iana' => 'America/Toronto', 'default' => true],
                ['iana' => 'America/Montreal', 'default' => false],
                ['iana' => 'America/Vancouver', 'default' => false],
                ['iana' => 'America/Edmonton', 'default' => false],
                ['iana' => 'America/Winnipeg', 'default' => false],
                ['iana' => 'America/Halifax', 'default' => false],
                ['iana' => 'America/St_Johns', 'default' => false],
                ['iana' => 'America/Regina', 'default' => false],
                ['iana' => 'America/Yellowknife', 'default' => false],
                ['iana' => 'America/Iqaluit', 'default' => false],
                ['iana' => 'America/Whitehorse', 'default' => false],
            ],
            'BR' => [
                ['iana' => 'America/Sao_Paulo', 'default' => true],
                ['iana' => 'America/Manaus', 'default' => false],
                ['iana' => 'America/Belem', 'default' => false],
                ['iana' => 'America/Fortaleza', 'default' => false],
                ['iana' => 'America/Recife', 'default' => false],
                ['iana' => 'America/Cuiaba', 'default' => false],
                ['iana' => 'America/Campo_Grande', 'default' => false],
                ['iana' => 'America/Rio_Branco', 'default' => false],
                ['iana' => 'America/Noronha', 'default' => false],
            ],
            'RU' => [
                ['iana' => 'Europe/Moscow', 'default' => true],
                ['iana' => 'Europe/Kaliningrad', 'default' => false],
                ['iana' => 'Europe/Samara', 'default' => false],
                ['iana' => 'Asia/Yekaterinburg', 'default' => false],
                ['iana' => 'Asia/Omsk', 'default' => false],
                ['iana' => 'Asia/Novosibirsk', 'default' => false],
                ['iana' => 'Asia/Krasnoyarsk', 'default' => false],
                ['iana' => 'Asia/Irkutsk', 'default' => false],
                ['iana' => 'Asia/Yakutsk', 'default' => false],
                ['iana' => 'Asia/Vladivostok', 'default' => false],
                ['iana' => 'Asia/Magadan', 'default' => false],
                ['iana' => 'Asia/Kamchatka', 'default' => false],
            ],
            'MX' => [
                ['iana' => 'America/Mexico_City', 'default' => true],
                ['iana' => 'America/Cancun', 'default' => false],
                ['iana' => 'America/Monterrey', 'default' => false],
                ['iana' => 'America/Tijuana', 'default' => false],
                ['iana' => 'America/Mazatlan', 'default' => false],
                ['iana' => 'America/Hermosillo', 'default' => false],
                ['iana' => 'America/Ciudad_Juarez', 'default' => false],
            ],
            'ES' => [
                ['iana' => 'Europe/Madrid', 'default' => true],
                ['iana' => 'Atlantic/Canary', 'default' => false],
            ],
            'PT' => [
                ['iana' => 'Europe/Lisbon', 'default' => true],
                ['iana' => 'Atlantic/Madeira', 'default' => false],
                ['iana' => 'Atlantic/Azores', 'default' => false],
            ],
            'CL' => [
                ['iana' => 'America/Santiago', 'default' => true],
                ['iana' => 'America/Punta_Arenas', 'default' => false],
                ['iana' => 'Pacific/Easter', 'default' => false],
            ],
            'NZ' => [
                ['iana' => 'Pacific/Auckland', 'default' => true],
                ['iana' => 'Pacific/Chatham', 'default' => false],
            ],
            'CD' => [
                ['iana' => 'Africa/Kinshasa', 'default' => true],
                ['iana' => 'Africa/Lubumbashi', 'default' => false],
            ],
            'EC' => [
                ['iana' => 'America/Guayaquil', 'default' => true],
                ['iana' => 'Pacific/Galapagos', 'default' => false],
            ],
            'KZ' => [
                ['iana' => 'Asia/Almaty', 'default' => true],
                ['iana' => 'Asia/Aqtobe', 'default' => false],
                ['iana' => 'Asia/Aqtau', 'default' => false],
                ['iana' => 'Asia/Atyrau', 'default' => false],
                ['iana' => 'Asia/Oral', 'default' => false],
                ['iana' => 'Asia/Qostanay', 'default' => false],
            ],
            'MN' => [
                ['iana' => 'Asia/Ulaanbaatar', 'default' => true],
                ['iana' => 'Asia/Hovd', 'default' => false],
                ['iana' => 'Asia/Choibalsan', 'default' => false],
            ],
            'CN' => [
                ['iana' => 'Asia/Shanghai', 'default' => true],
                ['iana' => 'Asia/Urumqi', 'default' => false],
            ],
        ];

        // 2. Iterate through all countries in ref_countries
        $countries = Country::all();

        foreach ($countries as $country) {
            $cc = strtoupper($country->code);

            if (isset($multiTimezoneCountries[$cc])) {
                foreach ($multiTimezoneCountries[$cc] as $tzDef) {
                    $this->upsertTimezone($country->code, $tzDef['iana'], $tzDef['default']);
                }
            } else {
                $iana = $country->timezone ?: 'UTC';
                $this->upsertTimezone($country->code, $iana, true);
            }
        }
    }

    private function upsertTimezone(string $countryCode, string $iana, bool $isDefault): void
    {
        try {
            $tzObj = new DateTimeZone($iana);
            $now = new DateTime('now', $tzObj);
            $offsetMinutes = $tzObj->getOffset($now) / 60;
            $hours = intdiv($offsetMinutes, 60);
            $minutes = abs($offsetMinutes % 60);
            $offsetSign = $hours >= 0 ? '+' : '-';
            $offsetString = sprintf('%s%02d:%02d', $offsetSign, abs($hours), $minutes);
        } catch (\Throwable) {
            $offsetString = '+00:00';
        }

        $displayName = "{$iana} (UTC{$offsetString})";

        $existing = TimeZone::where('country_code', $countryCode)
            ->where('iana_name', $iana)
            ->first();

        if ($existing) {
            $existing->update([
                'display_name' => $displayName,
                'utc_offset' => $offsetString,
                'is_default' => $isDefault,
                'active' => true,
            ]);
        } else {
            TimeZone::create([
                'id' => (string) Str::ulid(),
                'iana_name' => $iana,
                'display_name' => $displayName,
                'utc_offset' => $offsetString,
                'country_code' => $countryCode,
                'is_default' => $isDefault,
                'active' => true,
            ]);
        }
    }
}
