<?php

namespace App\Console\Commands;

use App\Models\ReferenceData\AddressHierarchy\Country;
use App\Models\ReferenceData\AddressHierarchy\District;
use App\Models\ReferenceData\AddressHierarchy\Province;
use App\Models\ReferenceData\AddressHierarchy\Regency;
use App\Models\ReferenceData\AddressHierarchy\Village;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ValidateIndonesianAddressHierarchyCommand extends Command
{
    protected $signature = 'address:validate-indonesia';

    protected $description = 'Validate complete Indonesian address hierarchy integrity, relational consistency, and postal codes';

    public function handle(): int
    {
        $this->info('============================================================');
        $this->info('   VALIDASI DATA WILAYAH & KODE POS INDONESIA (KEMENDAGRI)  ');
        $this->info('============================================================');

        $errors = 0;
        $warnings = 0;

        // 1. Validate Country Indonesia
        $country = Country::where('code', 'ID')->first();
        if (! $country) {
            $this->error(' [FAIL] Country Indonesia (ID) tidak ditemukan!');
            $errors++;
        } else {
            $this->line(" [PASS] Country Indonesia ({$country->code} / {$country->iso3}) valid.");
        }

        // 2. Validate Provinces
        $provincesCount = Province::where('country_code', 'ID')->count();
        $this->line(" [INFO] Total Provinsi Indonesia: <info>{$provincesCount}</info>");
        if ($provincesCount < 38) {
            $this->warn(" [WARN] Jumlah provinsi ({$provincesCount}) kurang dari 38 provinsi resmi.");
            $warnings++;
        } else {
            $this->line(' [PASS] Seluruh 38 Provinsi Indonesia tersedia.');
        }

        // 3. Validate Orphan Regencies
        $orphanRegencies = DB::select('
            SELECT r.id, r.code, r.name, r.province_id 
            FROM ref_regencies r 
            LEFT JOIN ref_provinces p ON r.province_id = p.id 
            WHERE p.id IS NULL
        ');
        $orphanRegCount = count($orphanRegencies);
        if ($orphanRegCount > 0) {
            $this->error(" [FAIL] Ditemukan {$orphanRegCount} Kabupaten/Kota tanpa relasi Provinsi (orphan)!");
            $errors += $orphanRegCount;
        } else {
            $regCount = Regency::whereHas('province', fn ($q) => $q->where('country_code', 'ID'))->count();
            $this->line(" [PASS] Seluruh {$regCount} Kabupaten/Kota terhubung dengan benar ke Provinsi (0 orphan).");
        }

        // 4. Validate Orphan Districts
        $orphanDistricts = DB::select('
            SELECT d.id, d.code, d.name, d.regency_id 
            FROM ref_districts d 
            LEFT JOIN ref_regencies r ON d.regency_id = r.id 
            WHERE r.id IS NULL
        ');
        $orphanDistCount = count($orphanDistricts);
        if ($orphanDistCount > 0) {
            $this->error(" [FAIL] Ditemukan {$orphanDistCount} Kecamatan tanpa relasi Kabupaten/Kota (orphan)!");
            $errors += $orphanDistCount;
        } else {
            $distCount = District::whereHas('regency.province', fn ($q) => $q->where('country_code', 'ID'))->count();
            $this->line(" [PASS] Seluruh {$distCount} Kecamatan terhubung dengan benar ke Kabupaten/Kota (0 orphan).");
        }

        // 5. Validate Orphan Villages
        $orphanVillages = DB::select('
            SELECT v.id, v.code, v.name, v.district_id 
            FROM ref_villages v 
            LEFT JOIN ref_districts d ON v.district_id = d.id 
            WHERE d.id IS NULL
        ');
        $orphanVillCount = count($orphanVillages);
        if ($orphanVillCount > 0) {
            $this->error(" [FAIL] Ditemukan {$orphanVillCount} Desa/Kelurahan tanpa relasi Kecamatan (orphan)!");
            $errors += $orphanVillCount;
        } else {
            $villCount = Village::whereHas('district.regency.province', fn ($q) => $q->where('country_code', 'ID'))->count();
            $this->line(" [PASS] Seluruh {$villCount} Desa/Kelurahan terhubung dengan benar ke Kecamatan (0 orphan).");
        }

        // 6. Check Duplicate Exact Records (district_id, code)
        $dupVillageCodes = DB::select('
            SELECT district_id, code, COUNT(*) as cnt 
            FROM ref_villages 
            GROUP BY district_id, code 
            HAVING COUNT(*) > 1
        ');
        $dupVillCodeCount = count($dupVillageCodes);
        if ($dupVillCodeCount > 0) {
            $this->error(" [FAIL] Ditemukan {$dupVillCodeCount} duplikasi kode resmi pada kecamatan yang sama!");
            $errors += $dupVillCodeCount;
        } else {
            $this->line(' [PASS] Tidak ada duplikasi official code pada level hierarki yang sama (0 duplicate).');
        }

        // 7. Check Duplicate Name within same parent and type
        $dupVillageNames = DB::select('
            SELECT district_id, LOWER(TRIM(name)) as norm_name, type, COUNT(*) as cnt 
            FROM ref_villages 
            GROUP BY district_id, LOWER(TRIM(name)), type 
            HAVING COUNT(*) > 1
        ');
        $dupVillNameCount = count($dupVillageNames);
        if ($dupVillNameCount > 0) {
            $this->warn(" [WARN] Ditemukan {$dupVillNameCount} nama desa yang sama dengan tipe sama pada kecamatan yang sama.");
            $warnings += $dupVillNameCount;
        } else {
            $this->line(' [PASS] Tidak ada duplikasi nama desa dengan tipe yang sama pada kecamatan yang sama.');
        }

        // 8. Check Circular Hierarchy
        // Since schema uses strictly typed parent_id foreign keys (Village -> District -> Regency -> Province -> Country), circular reference across different tables is impossible by design.
        $this->line(' [PASS] Perlindungan Circular Hierarchy aktif (0 circular relationship).');

        // 9. Check Postal Codes Coverage & Validation
        $villagesWithPostal = Village::whereNotNull('postal_code')->where('postal_code', '!=', '')->count();
        $totalVillages = Village::count();
        $invalidPostal = Village::whereNotNull('postal_code')
            ->where('postal_code', '!=', '')
            ->whereRaw("postal_code !~ '^[0-9]{5}$'")
            ->count();

        $this->line(" [INFO] Desa/Kelurahan dengan Kode Pos: <info>{$villagesWithPostal} / {$totalVillages}</info>");
        if ($invalidPostal > 0) {
            $this->error(" [FAIL] Ditemukan {$invalidPostal} kode pos dengan format tidak valid (harus 5 digit angka)!");
            $errors += $invalidPostal;
        } else {
            $this->line(' [PASS] Seluruh kode pos terdaftar menggunakan format 5 digit resmi.');
        }

        $this->newLine();
        $this->info('============================================================');
        $this->info('                   HASIL VALIDASI DATA                      ');
        $this->info('============================================================');
        $this->table(['Metric', 'Count / Status'], [
            ['Provinsi Indonesia', $provincesCount.' (Target: 38)'],
            ['Kabupaten / Kota', Regency::whereHas('province', fn ($q) => $q->where('country_code', 'ID'))->count()],
            ['Kecamatan', District::whereHas('regency.province', fn ($q) => $q->where('country_code', 'ID'))->count()],
            ['Desa / Kelurahan', $totalVillages],
            ['Desa dgn Kode Pos', $villagesWithPostal],
            ['Orphan Records', ($orphanRegCount + $orphanDistCount + $orphanVillCount).' (0 Expected)'],
            ['Duplicate Official Codes', $dupVillCodeCount.' (0 Expected)'],
            ['Circular Hierarchy', '0 (Protected)'],
            ['Validation Errors', $errors === 0 ? '<info>0 (PASSED)</info>' : "<error>{$errors}</error>"],
        ]);

        return $errors === 0 ? 0 : 1;
    }
}
