<?php

namespace Database\Seeders;

use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorldProvincesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seeds official ISO 3166-2 First-Level Administrative Subdivisions (States, Provinces, Prefectures, Regions)
     * for all 249 ISO 3166-1 countries and territories in the world.
     */
    public function run(): void
    {
        $now = now();

        // Load complete subdivisions dataset
        $subdivisionsPath = __DIR__.'/data/world_provinces_dataset.php';
        $countrySubdivisions = file_exists($subdivisionsPath) ? require $subdivisionsPath : [];

        $allCountries = Country::all();

        foreach ($allCountries as $country) {
            $cc = strtoupper($country->code);

            if (isset($countrySubdivisions[$cc]) && ! empty($countrySubdivisions[$cc])) {
                foreach ($countrySubdivisions[$cc] as $sub) {
                    $provCode = $sub['code'];
                    $provName = $sub['name'];
                    $provTz = $sub['timezone'] ?? $country->timezone ?? 'UTC';

                    $existing = Province::where('country_code', $cc)->where('code', $provCode)->first();
                    if ($existing) {
                        $existing->update([
                            'name' => $provName,
                            'description' => $provName,
                            'timezone' => $provTz,
                            'state_code' => $provCode,
                            'union_territory' => $sub['union_territory'] ?? false,
                            'active' => true,
                        ]);
                        $provId = $existing->id;
                    } else {
                        $provId = (string) Str::ulid();
                        Province::create([
                            'id' => $provId,
                            'country_code' => $cc,
                            'code' => $provCode,
                            'name' => $provName,
                            'description' => $provName,
                            'timezone' => $provTz,
                            'state_code' => $provCode,
                            'default_state' => false,
                            'union_territory' => $sub['union_territory'] ?? false,
                            'active' => true,
                        ]);
                    }

                    $existingTz = DB::table('ref_administrative_division_timezones')
                        ->where('division_type', 'province')
                        ->where('division_id', $provId)
                        ->where('timezone', $provTz)
                        ->first();

                    if ($existingTz) {
                        DB::table('ref_administrative_division_timezones')
                            ->where('id', $existingTz->id)
                            ->update([
                                'is_default' => true,
                                'status' => 'active',
                                'updated_at' => $now,
                            ]);
                    } else {
                        DB::table('ref_administrative_division_timezones')->insert([
                            'id' => (string) Str::ulid(),
                            'division_type' => 'province',
                            'division_id' => $provId,
                            'timezone' => $provTz,
                            'is_default' => true,
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            } else {
                // Ensure at least one primary / central state or capital division exists for this territory
                $provCode = $cc.'-01';
                $provName = $country->name.' (Central Division)';
                $provTz = $country->timezone ?: 'UTC';

                $existing = Province::where('country_code', $cc)->first();
                if (! $existing) {
                    $provId = (string) Str::ulid();
                    Province::create([
                        'id' => $provId,
                        'country_code' => $cc,
                        'code' => $provCode,
                        'name' => $provName,
                        'description' => $provName,
                        'timezone' => $provTz,
                        'state_code' => $provCode,
                        'default_state' => true,
                        'union_territory' => false,
                        'active' => true,
                    ]);

                    $existingTz = DB::table('ref_administrative_division_timezones')
                        ->where('division_type', 'province')
                        ->where('division_id', $provId)
                        ->where('timezone', $provTz)
                        ->first();

                    if ($existingTz) {
                        DB::table('ref_administrative_division_timezones')
                            ->where('id', $existingTz->id)
                            ->update([
                                'is_default' => true,
                                'status' => 'active',
                                'updated_at' => $now,
                            ]);
                    } else {
                        DB::table('ref_administrative_division_timezones')->insert([
                            'id' => (string) Str::ulid(),
                            'division_type' => 'province',
                            'division_id' => $provId,
                            'timezone' => $provTz,
                            'is_default' => true,
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }
    }
}
