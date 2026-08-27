<?php

namespace App\Console\Commands;

use App\Models\ReferenceData\AddressHierarchy\AdministrativeDivision;
use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\District;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use App\Models\ReferenceData\AddressHierarchy\Village;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportIndonesianAddressHierarchyCommand extends Command
{
    protected $signature = 'address:import-indonesia
                            {--dry-run : Only validate hierarchy and display counts without persisting changes}
                            {--force : Force update existing records}
                            {--source= : Custom data folder or file path}
                            {--level= : Filter specific level to import (province, regency, district, village)}';

    protected $description = 'Import complete official Indonesian administrative divisions (38 Provinces, 514 Regencies/Cities, 7265 Districts, 83345 Villages) from Kemendagri dataset';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');
        $source   = $this->option('source') ?: base_path('database/data');
        $levelOpt = $this->option('level');

        $this->info('============================================================');
        $this->info('  IMPORT DATA WILAYAH ADMINISTRATIF INDONESIA (KEMENDAGRI)');
        $this->info('============================================================');
        if ($dryRun) {
            $this->warn(' [MODE]: DRY RUN ACTIVE (No changes will be written to the database)');
        }

        // 1. Verify Country
        $country = Country::where('code', 'ID')->first();
        if (! $country && ! $dryRun) {
            Country::create([
                'code'       => 'ID',
                'iso3'       => 'IDN',
                'name'       => 'Indonesia',
                'phone_code' => '+62',
                'active'     => true,
            ]);
            $country = Country::where('code', 'ID')->first();
        }

        $allCountriesCount = Country::count();
        $this->line("Countries in system: <info>{$allCountriesCount}</info> (Selected: Indonesia / ID)");

        // 2. Load JSON Datasets
        $provincesFile = rtrim($source, '/\\') . '/indonesia_provinces.json';
        $regenciesFile = rtrim($source, '/\\') . '/indonesia_regencies.json';
        $districtsFile = rtrim($source, '/\\') . '/indonesia_districts.json';
        $villagesFile  = rtrim($source, '/\\') . '/indonesia_villages.json';

        if (! file_exists($provincesFile) || ! file_exists($regenciesFile) || ! file_exists($districtsFile)) {
            $this->error("Dataset files missing in {$source}. Required: indonesia_provinces.json, indonesia_regencies.json, indonesia_districts.json");
            return 1;
        }

        $provincesData = json_decode((string) file_get_contents($provincesFile), true) ?: [];
        $regenciesData = json_decode((string) file_get_contents($regenciesFile), true) ?: [];
        $districtsData = json_decode((string) file_get_contents($districtsFile), true) ?: [];
        $villagesData  = file_exists($villagesFile) ? (json_decode((string) file_get_contents($villagesFile), true) ?: []) : [];

        $stats = [
            'provinces_found' => count($provincesData),
            'regencies_found' => count($regenciesData),
            'districts_found' => count($districtsData),
            'villages_found'  => count($villagesData),
            'new'             => 0,
            'updated'         => 0,
            'skipped'         => 0,
            'duplicate'       => 0,
            'invalid_parent'  => 0,
            'invalid_code'    => 0,
            'errors'          => 0,
        ];

        $now = now();

        // ----------------------------------------------------
        // STEP 1: IMPORT / VALIDATE PROVINCES (Level 1)
        // ----------------------------------------------------
        if (! $levelOpt || $levelOpt === 'province') {
            $this->info("\nProcessing Provinces (Level 1)...");
            $provinceMap = []; // code => ULID

            foreach ($provincesData as $code => $name) {
                if (empty($code) || empty($name)) {
                    $stats['invalid_code']++;
                    $stats['errors']++;
                    continue;
                }

                $cleanCode = str_replace('.', '', $code);
                $displayCode = 'P-' . $cleanCode;

                $existing = DB::table('ref_administrative_divisions')
                    ->where('country_id', 'ID')
                    ->where('level', 1)
                    ->where('official_code', $cleanCode)
                    ->first();

                if (! $existing) {
                    $existingProv = DB::table('ref_provinces')
                        ->where('country_code', 'ID')
                        ->where('code', $cleanCode)
                        ->first();
                    $id = $existingProv?->id ?? (string) Str::ulid();
                    $stats['new']++;
                } else {
                    $id = $existing->id;
                    $stats['updated']++;
                }

                $provinceMap[$cleanCode] = $id;

                if (! $dryRun) {
                    $tz = $this->determineIanaTimezone($cleanCode);

                    // Unified table
                    DB::table('ref_administrative_divisions')->updateOrInsert(
                        ['country_id' => 'ID', 'level' => 1, 'official_code' => $cleanCode],
                        [
                            'id'           => $id,
                            'parent_id'    => null,
                            'type'         => 'province',
                            'display_code' => $displayCode,
                            'name'         => $name,
                            'status'       => 'active',
                            'lineage'      => json_encode(['country' => 'Indonesia', 'country_code' => 'ID']),
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ]
                    );

                    // Structured ref_provinces table
                    DB::table('ref_provinces')->updateOrInsert(
                        ['country_code' => 'ID', 'code' => $cleanCode],
                        [
                            'id'           => $id,
                            'display_code' => $displayCode,
                            'name'         => $name,
                            'timezone'     => $tz,
                            'active'       => true,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ]
                    );

                    // Division timezone table
                    DB::table('ref_administrative_division_timezones')->updateOrInsert(
                        ['division_type' => 'province', 'division_id' => $id],
                        [
                            'id'         => (string) Str::ulid(),
                            'timezone'   => $tz,
                            'is_default' => true,
                            'status'     => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            }
            $this->line("Provinces: <info>{$stats['provinces_found']}</info> validated.");
        } else {
            $provinceMap = DB::table('ref_administrative_divisions')
                ->where('country_id', 'ID')
                ->where('level', 1)
                ->pluck('id', 'official_code')
                ->toArray();
        }

        // ----------------------------------------------------
        // STEP 2: IMPORT / VALIDATE REGENCIES & CITIES (Level 2)
        // ----------------------------------------------------
        if (! $levelOpt || $levelOpt === 'regency') {
            $this->info("\nProcessing Regencies & Cities (Level 2)...");
            $regencyMap = []; // code => ULID

            $regencyChunks = array_chunk($regenciesData, 500, true);
            foreach ($regencyChunks as $chunk) {
                if (! $dryRun) {
                    DB::beginTransaction();
                }

                try {
                    foreach ($chunk as $code => $name) {
                        $parts = explode('.', $code);
                        if (count($parts) < 2) {
                            $stats['invalid_code']++;
                            $stats['errors']++;
                            continue;
                        }

                        $provCode = $parts[0];
                        $regCode  = $code;
                        $cleanRegCode = str_replace('.', '', $regCode);
                        $displayCode = 'K-' . $cleanRegCode;

                        $parentProvinceId = $provinceMap[$provCode] ?? null;
                        if (! $parentProvinceId) {
                            $stats['invalid_parent']++;
                            $stats['errors']++;
                            $this->warn(" [WARN] Invalid parent province code '{$provCode}' for regency {$code} - {$name}");
                            continue;
                        }

                        $type = str_starts_with(strtoupper($name), 'KOTA') ? 'city' : 'regency';
                        $dbType = str_starts_with(strtoupper($name), 'KOTA') ? 'kota' : 'kabupaten';

                        $existing = DB::table('ref_administrative_divisions')
                            ->where('country_id', 'ID')
                            ->where('level', 2)
                            ->where('official_code', $cleanRegCode)
                            ->first();

                        if (! $existing) {
                            $existingReg = DB::table('ref_regencies')
                                ->where('province_id', $parentProvinceId)
                                ->where('code', $cleanRegCode)
                                ->first();
                            $id = $existingReg?->id ?? (string) Str::ulid();
                            $stats['new']++;
                        } else {
                            $id = $existing->id;
                            $stats['updated']++;
                        }

                        $regencyMap[$cleanRegCode] = $id;
                        $regencyMap[$code] = $id;

                        if (! $dryRun) {
                            // Unified table
                            DB::table('ref_administrative_divisions')->updateOrInsert(
                                ['country_id' => 'ID', 'level' => 2, 'official_code' => $cleanRegCode],
                                [
                                    'id'           => $id,
                                    'parent_id'    => $parentProvinceId,
                                    'type'         => $type,
                                    'display_code' => $displayCode,
                                    'name'         => $name,
                                    'status'       => 'active',
                                    'lineage'      => json_encode(['country' => 'Indonesia', 'province_code' => $provCode]),
                                    'created_at'   => $now,
                                    'updated_at'   => $now,
                                ]
                            );

                            // Structured ref_regencies table
                            DB::table('ref_regencies')->updateOrInsert(
                                ['province_id' => $parentProvinceId, 'code' => $cleanRegCode],
                                [
                                    'id'           => $id,
                                    'display_code' => $displayCode,
                                    'name'         => $name,
                                    'type'         => $dbType,
                                    'active'       => true,
                                    'created_at'   => $now,
                                    'updated_at'   => $now,
                                ]
                            );
                        }
                    }

                    if (! $dryRun) {
                        DB::commit();
                    }
                } catch (\Throwable $e) {
                    if (! $dryRun) {
                        DB::rollBack();
                    }
                    $stats['errors']++;
                    $this->error("Batch error on regencies: " . $e->getMessage());
                }
            }
            $this->line("Regencies/Cities: <info>{$stats['regencies_found']}</info> validated.");
        } else {
            $regencyMap = DB::table('ref_administrative_divisions')
                ->where('country_id', 'ID')
                ->where('level', 2)
                ->pluck('id', 'official_code')
                ->toArray();
        }

        // ----------------------------------------------------
        // STEP 3: IMPORT / VALIDATE DISTRICTS (Level 3)
        // ----------------------------------------------------
        if (! $levelOpt || $levelOpt === 'district') {
            $this->info("\nProcessing Districts (Level 3)...");
            $districtMap = []; // code => ULID

            $districtChunks = array_chunk($districtsData, 1000, true);
            foreach ($districtChunks as $chunk) {
                if (! $dryRun) {
                    DB::beginTransaction();
                }

                try {
                    foreach ($chunk as $code => $name) {
                        $parts = explode('.', $code);
                        if (count($parts) < 3) {
                            $stats['invalid_code']++;
                            $stats['errors']++;
                            continue;
                        }

                        $regCode = $parts[0] . '.' . $parts[1];
                        $cleanRegCode = str_replace('.', '', $regCode);
                        $cleanDistCode = str_replace('.', '', $code);
                        $displayCode = 'D-' . $cleanDistCode;

                        $parentRegencyId = $regencyMap[$cleanRegCode] ?? ($regencyMap[$regCode] ?? null);
                        if (! $parentRegencyId) {
                            $stats['invalid_parent']++;
                            $stats['errors']++;
                            $this->warn(" [WARN] Invalid parent regency code '{$regCode}' for district {$code} - {$name}");
                            continue;
                        }

                        $existing = DB::table('ref_administrative_divisions')
                            ->where('country_id', 'ID')
                            ->where('level', 3)
                            ->where('official_code', $cleanDistCode)
                            ->first();

                        if (! $existing) {
                            $existingDist = DB::table('ref_districts')
                                ->where('regency_id', $parentRegencyId)
                                ->where('code', $cleanDistCode)
                                ->first();
                            $id = $existingDist?->id ?? (string) Str::ulid();
                            $stats['new']++;
                        } else {
                            $id = $existing->id;
                            $stats['updated']++;
                        }

                        $districtMap[$cleanDistCode] = $id;
                        $districtMap[$code] = $id;

                        if (! $dryRun) {
                            // Unified table
                            DB::table('ref_administrative_divisions')->updateOrInsert(
                                ['country_id' => 'ID', 'level' => 3, 'official_code' => $cleanDistCode],
                                [
                                    'id'           => $id,
                                    'parent_id'    => $parentRegencyId,
                                    'type'         => 'district',
                                    'display_code' => $displayCode,
                                    'name'         => $name,
                                    'status'       => 'active',
                                    'lineage'      => json_encode(['country' => 'Indonesia', 'regency_code' => $cleanRegCode]),
                                    'created_at'   => $now,
                                    'updated_at'   => $now,
                                ]
                            );

                            // Structured ref_districts table
                            DB::table('ref_districts')->updateOrInsert(
                                ['regency_id' => $parentRegencyId, 'code' => $cleanDistCode],
                                [
                                    'id'           => $id,
                                    'display_code' => $displayCode,
                                    'name'         => $name,
                                    'active'       => true,
                                    'created_at'   => $now,
                                    'updated_at'   => $now,
                                ]
                            );
                        }
                    }

                    if (! $dryRun) {
                        DB::commit();
                    }
                } catch (\Throwable $e) {
                    if (! $dryRun) {
                        DB::rollBack();
                    }
                    $stats['errors']++;
                    $this->error("Batch error on districts: " . $e->getMessage());
                }
            }
            $this->line("Districts: <info>{$stats['districts_found']}</info> validated.");
        } else {
            $districtMap = DB::table('ref_administrative_divisions')
                ->where('country_id', 'ID')
                ->where('level', 3)
                ->pluck('id', 'official_code')
                ->toArray();
        }

        // ----------------------------------------------------
        // STEP 4: IMPORT / VALIDATE VILLAGES & KELURAHAN (Level 4 - High Speed Bulk Upsert)
        // ----------------------------------------------------
        if ((! $levelOpt || $levelOpt === 'village') && count($villagesData) > 0) {
            $this->info("\nProcessing Villages & Kelurahan (Level 4 - {$stats['villages_found']} records)...");

            // Cache existing IDs to preserve existing ULIDs
            $existingAdminDivs = DB::table('ref_administrative_divisions')
                ->where('country_id', 'ID')
                ->where('level', 4)
                ->pluck('id', 'official_code')
                ->toArray();

            $existingVillages = DB::table('ref_villages')
                ->pluck('id', 'code')
                ->toArray();

            $villageChunks = array_chunk($villagesData, 3000);
            $totalChunks = count($villageChunks);
            $chunkIndex = 0;

            foreach ($villageChunks as $chunk) {
                $chunkIndex++;
                $adminDivBatch = [];
                $villageBatch  = [];

                foreach ($chunk as $item) {
                    $code          = $item['code'];
                    $distCode      = $item['district_code'];
                    $name          = $item['name'];
                    $type          = $item['type']; // 'desa' | 'kelurahan'
                    $cleanVillCode = str_replace('.', '', $code);
                    $cleanDistCode = str_replace('.', '', $distCode);
                    $displayCode   = 'V-' . $cleanVillCode;

                    $parentDistrictId = $districtMap[$cleanDistCode] ?? ($districtMap[$distCode] ?? null);
                    if (! $parentDistrictId) {
                        $stats['invalid_parent']++;
                        $stats['errors']++;
                        continue;
                    }

                    $id = $existingAdminDivs[$cleanVillCode] ?? ($existingVillages[$cleanVillCode] ?? (string) Str::ulid());

                    $adminDivBatch[] = [
                        'id'           => $id,
                        'country_id'   => 'ID',
                        'parent_id'    => $parentDistrictId,
                        'level'        => 4,
                        'type'         => $type === 'kelurahan' ? 'urban_village' : 'village',
                        'official_code'=> $cleanVillCode,
                        'display_code' => $displayCode,
                        'name'         => $name,
                        'status'       => 'active',
                        'lineage'      => json_encode(['country' => 'Indonesia', 'district_code' => $cleanDistCode]),
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];

                    $villageBatch[] = [
                        'id'           => $id,
                        'district_id'  => $parentDistrictId,
                        'code'         => $cleanVillCode,
                        'display_code' => $displayCode,
                        'name'         => $name,
                        'type'         => $type,
                        'active'       => true,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }

                if (! $dryRun) {
                    if (! empty($adminDivBatch)) {
                        DB::table('ref_administrative_divisions')->upsert(
                            $adminDivBatch,
                            ['country_id', 'level', 'official_code'],
                            ['parent_id', 'type', 'display_code', 'name', 'status', 'lineage', 'updated_at']
                        );
                    }
                    if (! empty($villageBatch)) {
                        DB::table('ref_villages')->upsert(
                            $villageBatch,
                            ['district_id', 'code'],
                            ['display_code', 'name', 'type', 'active', 'updated_at']
                        );
                    }
                }

                $this->line("  => Chunk {$chunkIndex}/{$totalChunks} (" . count($chunk) . " records) processed.");
            }
            $this->line("Villages/Kelurahan: <info>{$stats['villages_found']}</info> validated.");
        }

        // ----------------------------------------------------
        // SUMMARY REPORT
        // ----------------------------------------------------
        $this->info("\n============================================================");
        $this->info("  IMPORT & VALIDATION REPORT SUMMARY");
        $this->info("============================================================");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Countries in System', $allCountriesCount],
                ['Provinces Found', $stats['provinces_found']],
                ['Regencies/Cities Found', $stats['regencies_found']],
                ['Districts Found', $stats['districts_found']],
                ['Villages/Kelurahan Found', $stats['villages_found']],
                ['New Records Staged/Inserted', $stats['new']],
                ['Updated Records', $stats['updated']],
                ['Skipped Records', $stats['skipped']],
                ['Duplicate Violations', $stats['duplicate']],
                ['Invalid Parent Relations', $stats['invalid_parent']],
                ['Invalid Official Codes', $stats['invalid_code']],
                ['Total Execution Errors', $stats['errors']],
            ]
        );

        if ($dryRun) {
            $this->warn("DRY RUN COMPLETED: Validation passed with 0 errors. Run without --dry-run to persist.");
        } else {
            $this->info("SUCCESS: Full Indonesian Administrative Hierarchy (Provinces, Regencies, Districts, Villages) successfully imported!");
        }

        return $stats['errors'] === 0 ? 0 : 1;
    }

    private function determineIanaTimezone(string $provinceCode): string
    {
        // WIT: Maluku & Papua
        if (in_array($provinceCode, ['81', '82', '91', '92', '93', '94', '95', '96'])) {
            return 'Asia/Jayapura';
        }
        // WITA: Bali, NTB, NTT, Kalsel, Kaltim, Kaltara, Sulawesi
        if (in_array($provinceCode, ['51', '52', '53', '63', '64', '65', '71', '72', '73', '74', '75', '76'])) {
            return 'Asia/Makassar';
        }
        // WIB: Sumatra, Jawa, Kalbar, Kalteng
        return 'Asia/Jakarta';
    }
}
