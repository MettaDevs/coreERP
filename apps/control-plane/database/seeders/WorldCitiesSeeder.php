<?php

namespace Database\Seeders;

use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorldCitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seeds official cities, counties, municipalities, and administrative centers
     * for all countries and states/provinces of the world.
     */
    public function run(): void
    {
        $now = now();

        $citiesDatasetPath = __DIR__ . '/data/world_cities_dataset.php';
        $worldCities = file_exists($citiesDatasetPath) ? require $citiesDatasetPath : [];

        // Fetch all provinces grouped by country
        $allProvinces = Province::all();

        foreach ($allProvinces as $prov) {
            $cc = strtoupper($prov->country_code);
            $provCode = $prov->code;
            $provId = $prov->id;

            // Skip Indonesian provinces as they are already densely seeded by IndonesianAddressHierarchySeeder (514 cities)
            if ($cc === 'ID') {
                continue;
            }

            // Check if we have explicit city definitions for this country and province
            $cityList = $worldCities[$cc][$provCode] ?? null;

            // Try matching without country prefix (e.g. 'CA' instead of 'US-CA')
            if (!$cityList && str_contains($provCode, '-')) {
                $suffix = substr($provCode, strpos($provCode, '-') + 1);
                $cityList = $worldCities[$cc][$suffix] ?? null;
            }

            if ($cityList && is_array($cityList) && count($cityList) > 0) {
                foreach ($cityList as $cData) {
                    $cityCode = $cData['code'];
                    $cityName = $cData['name'];
                    $rawType  = $cData['type'] ?? 'city';
                    $cityType = match ($rawType) {
                        'special_administrative_area' => 'special_area',
                        'metropolitan_municipality'   => 'metro_municipality',
                        'metropolitan_borough'        => 'metro_borough',
                        'highly_urbanized_city'       => 'urban_city',
                        'sub-provincial_city'         => 'subprov_city',
                        'prefecture-level_city'       => 'pref_city',
                        'federal_territory'           => 'fed_territory',
                        'federal_district'            => 'fed_district',
                        'province_capital'            => 'capital_city',
                        default                       => substr($rawType, 0, 20),
                    };
                    $cityDesc = $cData['description'] ?? ($cityName . ', ' . $prov->name);

                    $existing = Regency::where('province_id', $provId)->where('code', $cityCode)->first();
                    if ($existing) {
                        $existing->update([
                            'name'        => $cityName,
                            'description' => $cityDesc,
                            'type'        => $cityType,
                            'active'      => true,
                        ]);
                    } else {
                        Regency::create([
                            'id'          => (string) Str::ulid(),
                            'province_id' => $provId,
                            'code'        => $cityCode,
                            'name'        => $cityName,
                            'description' => $cityDesc,
                            'type'        => $cityType,
                            'active'      => true,
                        ]);
                    }
                }
            } else {
                // Ensure at least one primary city / central municipality exists for this province
                $cleanProvName = preg_replace('/\s*\((.*?)\)\s*/', '', $prov->name);
                $cityName = $cleanProvName . ' (Central City)';
                $cityCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $provCode), -5) ?: 'CTY') . '-01';
                $cityType = 'city';
                $cityDesc = "Primary administrative center of {$prov->name}";

                $existing = Regency::where('province_id', $provId)->first();
                if (!$existing) {
                    Regency::create([
                        'id'          => (string) Str::ulid(),
                        'province_id' => $provId,
                        'code'        => $cityCode,
                        'name'        => $cityName,
                        'description' => $cityDesc,
                        'type'        => $cityType,
                        'active'      => true,
                    ]);
                }
            }
        }
    }
}
