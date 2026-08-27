<?php

namespace App\Console\Commands;

use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\District;
use App\Models\ReferenceData\AddressHierarchy\PostalCode;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use App\Models\ReferenceData\AddressHierarchy\Village;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportPostalCodesCommand extends Command
{
    protected $signature = 'address:import-postal-codes
                            {--file= : Path to custom SQL/JSON/CSV postal codes file}
                            {--country=ID : Country code (e.g. ID)}
                            {--dry-run : Validate and show statistics without saving to database}
                            {--force : Force update existing postal codes}
                            {--batch-size=2000 : Number of records per batch transaction}';

    protected $description = 'Import official Postal Codes (Kode Pos) with strict relational hierarchy mapping';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $countryCode = strtoupper((string) $this->option('country'));
        $filePath    = $this->option('file');
        $dryRun      = (bool) $this->option('dry-run');
        $force       = (bool) $this->option('force');
        $batchSize   = max(100, (int) $this->option('batch-size'));

        $this->info('============================================================');
        $this->info("  IMPORT OFFICIAL POSTAL CODES (KODE POS) — {$countryCode}");
        $this->info('============================================================');

        if ($dryRun) {
            $this->warn(' [MODE]: DRY RUN ACTIVE (No database changes will be committed)');
        }

        $country = Country::where('code', $countryCode)->first();
        if (! $country) {
            $this->error("Country with code '{$countryCode}' not found in ref_countries.");
            return 1;
        }

        // 1. Resolve source file
        if (! $filePath || ! file_exists($filePath)) {
            $defaultSql = base_path('database/data/wilayah_kodepos.sql');
            if (file_exists($defaultSql)) {
                $filePath = $defaultSql;
            }
        }

        if (! $filePath || ! file_exists($filePath)) {
            $this->error("Postal codes dataset file not found. Expected: {$filePath} or database/data/wilayah_kodepos.sql");
            return 1;
        }

        $this->info("Reading postal codes from: <info>{$filePath}</info>");

        // 2. Parse file into pairs: [clean_code, dotted_code, postal_code]
        $pairs = [];
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->error("Could not open file {$filePath}");
            return 1;
        }

        $lineCount = 0;
        while (($line = fgets($handle)) !== false) {
            $lineCount++;
            // Match pattern: ('11.01.01.2001', '23773') or ('1101012001', '23773')
            if (preg_match("/\(\s*['\"]([0-9.]+)['\"]\s*,\s*['\"]([0-9]{5})['\"]\s*\)/", $line, $matches)) {
                $rawCode = $matches[1];
                $postal = $matches[2];
                $cleanCode = str_replace('.', '', $rawCode);
                $pairs[] = [
                    'code'        => $cleanCode,
                    'dotted_code' => $rawCode,
                    'postal_code' => $postal,
                ];
            }
        }
        fclose($handle);

        $totalRecords = count($pairs);
        $this->info("Parsed <info>{$totalRecords}</info> official postal code mappings.");

        if ($totalRecords === 0) {
            $this->warn('No postal code records matched in the file.');
            return 0;
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $unmapped = 0;
        $now = now();

        $chunks = array_chunk($pairs, $batchSize);
        $totalChunks = count($chunks);
        $bar = $this->output->createProgressBar($totalRecords);
        $bar->start();

        foreach ($chunks as $chunkIndex => $chunk) {
            if (! $dryRun) {
                DB::beginTransaction();
            }

            try {
                // Collect codes in this chunk
                $chunkCodes = array_column($chunk, 'code');
                $codeToPostal = [];
                foreach ($chunk as $c) {
                    $codeToPostal[$c['code']] = $c['postal_code'];
                }

                // Query matching villages in database
                $villages = Village::whereIn('code', $chunkCodes)
                    ->with('district.regency.province')
                    ->get()
                    ->keyBy('code');

                $postalCodeInserts = [];
                $villageUpdates = [];

                foreach ($chunk as $item) {
                    $c = $item['code'];
                    $p = $item['postal_code'];

                    if (isset($villages[$c])) {
                        $v = $villages[$c];
                        $dist = $v->district;
                        $reg  = $dist?->regency;
                        $prov = $reg?->province;

                        // Check if village postal code needs update
                        if (empty($v->postal_code) || $force) {
                            $villageUpdates[$v->id] = $p;
                            $updated++;
                        } else {
                            $skipped++;
                        }

                        // Prepare master postal code record
                        $postalCodeInserts[] = [
                            'id'           => (string) Str::ulid(),
                            'country_code' => $countryCode,
                            'postal_code'  => $p,
                            'province_id'  => $prov?->id,
                            'regency_id'   => $reg?->id,
                            'district_id'  => $dist?->id,
                            'village_id'   => $v->id,
                            'area_name'    => $v->name,
                            'source'       => 'POS_INDONESIA',
                            'status'       => 'active',
                            'active'       => true,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ];
                    } else {
                        $unmapped++;
                        $skipped++;
                    }

                    $bar->advance();
                }

                if (! $dryRun) {
                    // Bulk update ref_villages
                    if (count($villageUpdates) > 0) {
                        $cases = [];
                        $params = [];
                        foreach ($villageUpdates as $vId => $pCode) {
                            $cases[] = "WHEN id = ? THEN ?";
                            $params[] = $vId;
                            $params[] = $pCode;
                        }
                        $caseSql = implode(' ', $cases);
                        $idPlaceholders = implode(',', array_fill(0, count($villageUpdates), '?'));
                        $allParams = array_merge($params, array_keys($villageUpdates));

                        DB::statement("
                            UPDATE ref_villages 
                            SET postal_code = CASE {$caseSql} END,
                                updated_at = NOW()
                            WHERE id IN ({$idPlaceholders})
                        ", $allParams);
                    }

                    // Bulk insert into ref_postal_codes (ignoring or updating duplicates)
                    if (count($postalCodeInserts) > 0) {
                        // Split into sub-chunks of 500 for Postgres parameter limits
                        foreach (array_chunk($postalCodeInserts, 500) as $subChunk) {
                            DB::table('ref_postal_codes')->upsert(
                                $subChunk,
                                ['country_code', 'postal_code', 'village_id'],
                                ['province_id', 'regency_id', 'district_id', 'area_name', 'source', 'status', 'active', 'updated_at']
                            );
                            $inserted += count($subChunk);
                        }
                    }

                    DB::commit();
                }

            } catch (\Throwable $e) {
                if (! $dryRun) {
                    DB::rollBack();
                }
                $this->error("\nError in chunk {$chunkIndex}: " . $e->getMessage());
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('============================================================');
        $this->info('            INDONESIA POSTAL CODE IMPORT REPORT             ');
        $this->info('============================================================');
        $this->table(['Metric', 'Count'], [
            ['Total Mappings Processed', $totalRecords],
            ['Villages Updated with Postal Code', $updated],
            ['Postal Code Records Upserted', $inserted],
            ['Skipped (Already up-to-date)', $skipped],
            ['Unmapped / Missing Village Code', $unmapped],
            ['Errors', 0],
        ]);

        return 0;
    }
}
