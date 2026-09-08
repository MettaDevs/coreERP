<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IndonesianAddressHierarchySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ===== COUNTRIES =====
        $countries = [
            ['code' => 'ID', 'iso3' => 'IDN', 'name' => 'Indonesia', 'phone_code' => '+62', 'timezone' => 'Asia/Jakarta'],
            ['code' => 'MY', 'iso3' => 'MYS', 'name' => 'Malaysia', 'phone_code' => '+60', 'timezone' => 'Asia/Kuala_Lumpur'],
            ['code' => 'SG', 'iso3' => 'SGP', 'name' => 'Singapore', 'phone_code' => '+65', 'timezone' => 'Asia/Singapore'],
            ['code' => 'TH', 'iso3' => 'THA', 'name' => 'Thailand', 'phone_code' => '+66', 'timezone' => 'Asia/Bangkok'],
            ['code' => 'VN', 'iso3' => 'VNM', 'name' => 'Vietnam', 'phone_code' => '+84', 'timezone' => 'Asia/Ho_Chi_Minh'],
            ['code' => 'PH', 'iso3' => 'PHL', 'name' => 'Philippines', 'phone_code' => '+63', 'timezone' => 'Asia/Manila'],
            ['code' => 'BN', 'iso3' => 'BRN', 'name' => 'Brunei Darussalam', 'phone_code' => '+673', 'timezone' => 'Asia/Brunei'],
            ['code' => 'KH', 'iso3' => 'KHM', 'name' => 'Cambodia', 'phone_code' => '+855', 'timezone' => 'Asia/Phnom_Penh'],
            ['code' => 'LA', 'iso3' => 'LAO', 'name' => 'Laos', 'phone_code' => '+856', 'timezone' => 'Asia/Vientiane'],
            ['code' => 'MM', 'iso3' => 'MMR', 'name' => 'Myanmar', 'phone_code' => '+95', 'timezone' => 'Asia/Yangon'],
            ['code' => 'TL', 'iso3' => 'TLS', 'name' => 'Timor-Leste', 'phone_code' => '+670', 'timezone' => 'Asia/Dili'],
        ];

        foreach ($countries as $c) {
            DB::table('ref_countries')->updateOrInsert(
                ['code' => $c['code']],
                array_merge($c, ['active' => true, 'created_at' => $now, 'updated_at' => $now])
            );

            DB::table('ref_administrative_division_timezones')->updateOrInsert(
                ['division_type' => 'country', 'division_id' => $c['code']],
                ['id' => (string) Str::ulid(), 'timezone' => $c['timezone'], 'is_default' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
            );
        }

        // ===== ADDRESS PARAMETERS =====
        DB::table('ref_address_parameters')->updateOrInsert(
            ['country_code' => 'ID'],
            [
                'country_code' => 'ID',
                'use_province' => true,
                'use_regency' => true,
                'use_district' => true,
                'use_village' => true,
                'use_rt_rw' => true,
                'use_postal_code' => true,
                'use_building' => true,
                'address_format' => '{street}, RT {rt}/RW {rw}, Kel. {village}, Kec. {district}, {regency}, {province} {postal_code}',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        DB::table('ref_address_parameters')->updateOrInsert(
            ['country_code' => 'MY'],
            [
                'country_code' => 'MY',
                'use_province' => true,
                'use_regency' => true,
                'use_district' => true,
                'use_village' => true,
                'use_rt_rw' => false,
                'use_postal_code' => true,
                'use_building' => true,
                'address_format' => '{street}, {village}, {district}, {postal_code} {regency}, {province}',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // ===== COUNTRY HIERARCHY LEVEL LABELS =====
        $this->seedHierarchyLevels($now);

        // ===== INDONESIA =====
        $this->seedIndonesia($now);
    }

    private function seedHierarchyLevels(string $now): void
    {
        $levels = [
            // Indonesia
            ['country_code' => 'ID', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Provinsi', 'description' => 'Tingkat 1: Provinsi'],
            ['country_code' => 'ID', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Kabupaten/Kota', 'description' => 'Tingkat 2: Kabupaten & Kota'],
            ['country_code' => 'ID', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Kecamatan', 'description' => 'Tingkat 3: Kecamatan / Distrik'],
            ['country_code' => 'ID', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Desa/Kelurahan', 'description' => 'Tingkat 4: Desa, Kelurahan, Kampung, Nagari'],
            ['country_code' => 'ID', 'level' => 5, 'level_code' => 'street',   'level_name' => 'RT / RW', 'description' => 'Tingkat 5: Rukun Tetangga / Rukun Warga'],

            // Malaysia
            ['country_code' => 'MY', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Negeri / State', 'description' => 'Tingkat 1: Negeri / State'],
            ['country_code' => 'MY', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Daerah / District', 'description' => 'Tingkat 2: Daerah / District'],
            ['country_code' => 'MY', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Mukim / Sub-district', 'description' => 'Tingkat 3: Mukim / Sub-district'],
            ['country_code' => 'MY', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Bandar / Kampung', 'description' => 'Tingkat 4: Bandar / Kampung'],
            // Singapore
            ['country_code' => 'SG', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Region / District', 'description' => 'Tingkat 1: Region / District'],
            ['country_code' => 'SG', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Planning Area', 'description' => 'Tingkat 2: Planning Area'],
            ['country_code' => 'SG', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Subzone', 'description' => 'Tingkat 3: Subzone'],
            ['country_code' => 'SG', 'level' => 4, 'level_code' => 'village',  'level_name' => 'Neighborhood / Estate', 'description' => 'Tingkat 4: Estate'],
            ['country_code' => 'SG', 'level' => 5, 'level_code' => 'street',   'level_name' => 'Street / Avenue', 'description' => 'Tingkat 5: Street'],

            // Brunei
            ['country_code' => 'BN', 'level' => 1, 'level_code' => 'province', 'level_name' => 'Daerah', 'description' => 'Tingkat 1: Daerah'],
            ['country_code' => 'BN', 'level' => 2, 'level_code' => 'regency',  'level_name' => 'Mukim', 'description' => 'Tingkat 2: Mukim'],
            ['country_code' => 'BN', 'level' => 3, 'level_code' => 'district', 'level_name' => 'Kampong', 'description' => 'Tingkat 3: Kampong'],

            ['country_code' => 'ID', 'level' => 6, 'level_code' => 'building', 'level_name' => 'Gedung / Unit / Lantai', 'description' => 'Tingkat 6: Gedung, Blok, Unit, Lantai'],
        ];

        foreach ($levels as $lvl) {
            DB::table('ref_country_hierarchy_levels')->updateOrInsert(
                ['country_code' => $lvl['country_code'], 'level' => $lvl['level']],
                array_merge($lvl, [
                    'id' => (string) Str::ulid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    private function determineIanaTimezone(string $countryCode, string $provinceCode): string
    {
        if ($countryCode === 'ID') {
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
        if (in_array($countryCode, ['MY'])) {
            return 'Asia/Kuala_Lumpur';
        }
        if (in_array($countryCode, ['SG'])) {
            return 'Asia/Singapore';
        }
        if (in_array($countryCode, ['BN'])) {
            return 'Asia/Brunei';
        }
        if (in_array($countryCode, ['PH'])) {
            return 'Asia/Manila';
        }
        if (in_array($countryCode, ['TH'])) {
            return 'Asia/Bangkok';
        }
        if (in_array($countryCode, ['VN'])) {
            return 'Asia/Ho_Chi_Minh';
        }
        if (in_array($countryCode, ['KH'])) {
            return 'Asia/Phnom_Penh';
        }
        if (in_array($countryCode, ['LA'])) {
            return 'Asia/Vientiane';
        }
        if ($countryCode === 'MM') {
            return 'Asia/Yangon';
        }
        if ($countryCode === 'TL') {
            return 'Asia/Dili';
        }

        return 'Asia/Jakarta';
    }

    private function upsertProvince(string $countryCode, string $code, string $name, string $now, ?string $ianaTimezone = null): string
    {
        $iana = $ianaTimezone ?? $this->determineIanaTimezone($countryCode, $code);
        $existing = DB::table('ref_provinces')->where('country_code', $countryCode)->where('code', $code)->first();
        $id = $existing ? (string) $existing->id : (string) Str::ulid();
        DB::table('ref_provinces')->updateOrInsert(
            ['country_code' => $countryCode, 'code' => $code],
            ['id' => $id, 'name' => $name, 'timezone' => $iana, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        // Map to ref_administrative_division_timezones using the province ULID ID
        DB::table('ref_administrative_division_timezones')->updateOrInsert(
            ['division_type' => 'province', 'division_id' => $id, 'timezone' => $iana],
            ['id' => (string) Str::ulid(), 'is_default' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
        );

        return $id;
    }

    private function upsertRegency(string $provinceId, string $code, string $name, string $type, string $now): string
    {
        $existing = DB::table('ref_regencies')->where('province_id', $provinceId)->where('code', $code)->first();
        $id = $existing ? (string) $existing->id : (string) Str::ulid();
        DB::table('ref_regencies')->updateOrInsert(
            ['province_id' => $provinceId, 'code' => $code],
            ['id' => $id, 'name' => $name, 'type' => $type, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        return $id;
    }

    private function upsertDistrict(string $regencyId, string $code, string $name, string $now): string
    {
        $existing = DB::table('ref_districts')->where('regency_id', $regencyId)->where('code', $code)->first();
        $id = $existing ? (string) $existing->id : (string) Str::ulid();
        DB::table('ref_districts')->updateOrInsert(
            ['regency_id' => $regencyId, 'code' => $code],
            ['id' => $id, 'name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        return $id;
    }

    private function upsertVillage(string $districtId, string $code, string $name, string $type, ?string $postal, string $now): string
    {
        $existing = DB::table('ref_villages')->where('district_id', $districtId)->where('code', $code)->first();
        $id = $existing ? (string) $existing->id : (string) Str::ulid();
        DB::table('ref_villages')->updateOrInsert(
            ['district_id' => $districtId, 'code' => $code],
            ['id' => $id, 'name' => $name, 'type' => $type, 'postal_code' => $postal, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        return $id;
    }

    private function upsertStreet(string $villageId, string $rt, string $rw, ?string $name, string $now): string
    {
        $existing = DB::table('ref_streets')->where('village_id', $villageId)->where('rt', $rt)->where('rw', $rw)->first();
        $id = $existing ? (string) $existing->id : (string) Str::ulid();
        DB::table('ref_streets')->updateOrInsert(
            ['village_id' => $villageId, 'rt' => $rt, 'rw' => $rw],
            ['id' => $id, 'name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        return $id;
    }

    private function upsertPostalCode(string $countryCode, string $postal, ?string $provinceId, ?string $regencyId, ?string $districtId, ?string $villageId, string $now): void
    {
        DB::table('ref_postal_codes')->updateOrInsert(
            ['country_code' => $countryCode, 'postal_code' => $postal, 'village_id' => $villageId],
            [
                'id' => (string) Str::ulid(),
                'province_id' => $provinceId,
                'regency_id' => $regencyId,
                'district_id' => $districtId,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    // ==================== INDONESIA ====================
    private function seedIndonesia(string $now): void
    {
        // --- DKI Jakarta ---
        $jkt = $this->upsertProvince('ID', '31', 'DKI Jakarta', $now);

        $jakPusat = $this->upsertRegency($jkt, '3171', 'Kota Jakarta Pusat', 'kota', $now);
        $jakSel = $this->upsertRegency($jkt, '3174', 'Kota Jakarta Selatan', 'kota', $now);
        $jakBrt = $this->upsertRegency($jkt, '3173', 'Kota Jakarta Barat', 'kota', $now);
        $jakTim = $this->upsertRegency($jkt, '3175', 'Kota Jakarta Timur', 'kota', $now);
        $jakUtr = $this->upsertRegency($jkt, '3172', 'Kota Jakarta Utara', 'kota', $now);

        $gambir = $this->upsertDistrict($jakPusat, '317101', 'Gambir', $now);
        $tnahAbang = $this->upsertDistrict($jakPusat, '317102', 'Tanah Abang', $now);
        $menteng = $this->upsertDistrict($jakPusat, '317103', 'Menteng', $now);
        $senen = $this->upsertDistrict($jakPusat, '317104', 'Senen', $now);

        $vGambir = $this->upsertVillage($gambir, '3171011001', 'Gambir', 'kelurahan', '10110', $now);
        $vKebKlp = $this->upsertVillage($gambir, '3171011002', 'Kebon Kelapa', 'kelurahan', '10120', $now);
        $vPetojoS = $this->upsertVillage($gambir, '3171011003', 'Petojo Selatan', 'kelurahan', '10130', $now);

        $vBendHilir = $this->upsertVillage($tnahAbang, '3171021001', 'Bendungan Hilir', 'kelurahan', '10210', $now);
        $vKarTngsin = $this->upsertVillage($tnahAbang, '3171021002', 'Karet Tengsin', 'kelurahan', '10220', $now);

        $this->upsertStreet($vGambir, '01', '01', 'Jl. Gambir Baru', $now);
        $this->upsertStreet($vGambir, '02', '01', 'Jl. Cideng Timur', $now);
        $this->upsertStreet($vKebKlp, '01', '02', 'Jl. Kebon Kelapa Raya', $now);
        $this->upsertStreet($vBendHilir, '01', '01', 'Jl. Jenderal Sudirman', $now);

        $this->upsertPostalCode('ID', '10110', $jkt, $jakPusat, $gambir, $vGambir, $now);
        $this->upsertPostalCode('ID', '10120', $jkt, $jakPusat, $gambir, $vKebKlp, $now);
        $this->upsertPostalCode('ID', '10130', $jkt, $jakPusat, $gambir, $vPetojoS, $now);
        $this->upsertPostalCode('ID', '10210', $jkt, $jakPusat, $tnahAbang, $vBendHilir, $now);
        $this->upsertPostalCode('ID', '10220', $jkt, $jakPusat, $tnahAbang, $vKarTngsin, $now);

        // --- Jawa Barat (27 Official Regencies / Cities) ---
        $jabar = $this->upsertProvince('ID', '32', 'Jawa Barat', $now);
        $this->upsertRegency($jabar, '3201', 'Kabupaten Bogor', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3202', 'Kabupaten Sukabumi', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3203', 'Kabupaten Cianjur', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3204', 'Kabupaten Bandung', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3205', 'Kabupaten Garut', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3206', 'Kabupaten Tasikmalaya', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3207', 'Kabupaten Ciamis', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3208', 'Kabupaten Kuningan', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3209', 'Kabupaten Cirebon', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3210', 'Kabupaten Majalengka', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3211', 'Kabupaten Sumedang', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3212', 'Kabupaten Indramayu', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3213', 'Kabupaten Subang', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3214', 'Kabupaten Purwakarta', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3215', 'Kabupaten Karawang', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3216', 'Kabupaten Bekasi', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3217', 'Kabupaten Bandung Barat', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3218', 'Kabupaten Pangandaran', 'kabupaten', $now);
        $this->upsertRegency($jabar, '3271', 'Kota Bogor', 'kota', $now);
        $this->upsertRegency($jabar, '3272', 'Kota Sukabumi', 'kota', $now);
        $bandung = $this->upsertRegency($jabar, '3273', 'Kota Bandung', 'kota', $now);
        $this->upsertRegency($jabar, '3274', 'Kota Cirebon', 'kota', $now);
        $this->upsertRegency($jabar, '3275', 'Kota Bekasi', 'kota', $now);
        $this->upsertRegency($jabar, '3276', 'Kota Depok', 'kota', $now);
        $this->upsertRegency($jabar, '3277', 'Kota Cimahi', 'kota', $now);
        $this->upsertRegency($jabar, '3278', 'Kota Tasikmalaya', 'kota', $now);
        $this->upsertRegency($jabar, '3279', 'Kota Banjar', 'kota', $now);

        $coblong = $this->upsertDistrict($bandung, '327301', 'Coblong', $now);
        $sumurBdg = $this->upsertDistrict($bandung, '327302', 'Sumur Bandung', $now);

        $vDago = $this->upsertVillage($coblong, '3273011001', 'Dago', 'kelurahan', '40135', $now);
        $vLebakgd = $this->upsertVillage($coblong, '3273011002', 'Lebakgede', 'kelurahan', '40132', $now);
        $vSadang = $this->upsertVillage($coblong, '3273011003', 'Sadang Serang', 'kelurahan', '40133', $now);

        $this->upsertStreet($vDago, '01', '01', 'Jl. Ir. H. Juanda', $now);
        $this->upsertStreet($vDago, '02', '02', 'Jl. Dago Pojok', $now);
        $this->upsertPostalCode('ID', '40135', $jabar, $bandung, $coblong, $vDago, $now);
        $this->upsertPostalCode('ID', '40132', $jabar, $bandung, $coblong, $vLebakgd, $now);

        // --- Banten ---
        $banten = $this->upsertProvince('ID', '36', 'Banten', $now);
        $tangerang = $this->upsertRegency($banten, '3671', 'Kota Tangerang', 'kota', $now);
        $tangsel = $this->upsertRegency($banten, '3674', 'Kota Tangerang Selatan', 'kota', $now);
        $kabTgr = $this->upsertRegency($banten, '3603', 'Kabupaten Tangerang', 'kabupaten', $now);

        // --- Jawa Tengah ---
        $jateng = $this->upsertProvince('ID', '33', 'Jawa Tengah', $now);
        $semarang = $this->upsertRegency($jateng, '3374', 'Kota Semarang', 'kota', $now);
        $solo = $this->upsertRegency($jateng, '3372', 'Kota Surakarta', 'kota', $now);

        $smgTengah = $this->upsertDistrict($semarang, '337401', 'Semarang Tengah', $now);
        $vSekayu = $this->upsertVillage($smgTengah, '3374011001', 'Sekayu', 'kelurahan', '50132', $now);
        $this->upsertStreet($vSekayu, '01', '01', 'Jl. Pemuda', $now);
        $this->upsertPostalCode('ID', '50132', $jateng, $semarang, $smgTengah, $vSekayu, $now);

        // --- DI Yogyakarta ---
        $diy = $this->upsertProvince('ID', '34', 'DI Yogyakarta', $now);
        $yogya = $this->upsertRegency($diy, '3471', 'Kota Yogyakarta', 'kota', $now);
        $sleman = $this->upsertRegency($diy, '3404', 'Kabupaten Sleman', 'kabupaten', $now);

        $danurejan = $this->upsertDistrict($yogya, '347101', 'Danurejan', $now);
        $vSuryatm = $this->upsertVillage($danurejan, '3471011001', 'Suryatmajan', 'kelurahan', '55213', $now);
        $this->upsertStreet($vSuryatm, '01', '01', 'Jl. Sultan Agung', $now);
        $this->upsertPostalCode('ID', '55213', $diy, $yogya, $danurejan, $vSuryatm, $now);

        // --- Jawa Timur ---
        $jatim = $this->upsertProvince('ID', '35', 'Jawa Timur', $now);
        $surabaya = $this->upsertRegency($jatim, '3578', 'Kota Surabaya', 'kota', $now);
        $malang = $this->upsertRegency($jatim, '3573', 'Kota Malang', 'kota', $now);

        $tegalsari = $this->upsertDistrict($surabaya, '357801', 'Tegalsari', $now);
        $vTegalsari = $this->upsertVillage($tegalsari, '3578011001', 'Tegalsari', 'kelurahan', '60262', $now);
        $vWonorejo = $this->upsertVillage($tegalsari, '3578011002', 'Wonorejo', 'kelurahan', '60263', $now);
        $this->upsertStreet($vTegalsari, '01', '01', 'Jl. Raya Darmo', $now);
        $this->upsertPostalCode('ID', '60262', $jatim, $surabaya, $tegalsari, $vTegalsari, $now);
        $this->upsertPostalCode('ID', '60263', $jatim, $surabaya, $tegalsari, $vWonorejo, $now);

        // --- Bali (9 Official Regencies / Cities) ---
        $bali = $this->upsertProvince('ID', '51', 'Bali', $now);
        $jembrana = $this->upsertRegency($bali, '5101', 'Kabupaten Jembrana', 'kabupaten', $now);
        $tabanan = $this->upsertRegency($bali, '5102', 'Kabupaten Tabanan', 'kabupaten', $now);
        $badung = $this->upsertRegency($bali, '5103', 'Kabupaten Badung', 'kabupaten', $now);
        $gianyar = $this->upsertRegency($bali, '5104', 'Kabupaten Gianyar', 'kabupaten', $now);
        $klungkung = $this->upsertRegency($bali, '5105', 'Kabupaten Klungkung', 'kabupaten', $now);
        $bangli = $this->upsertRegency($bali, '5106', 'Kabupaten Bangli', 'kabupaten', $now);
        $karangasem = $this->upsertRegency($bali, '5107', 'Kabupaten Karangasem', 'kabupaten', $now);
        $buleleng = $this->upsertRegency($bali, '5108', 'Kabupaten Buleleng', 'kabupaten', $now);
        $denpasar = $this->upsertRegency($bali, '5171', 'Kota Denpasar', 'kota', $now);

        // Denpasar Districts & Villages
        $denSel = $this->upsertDistrict($denpasar, '517101', 'Denpasar Selatan', $now);
        $vSanur = $this->upsertVillage($denSel, '5171011001', 'Sanur', 'kelurahan', '80228', $now);
        $this->upsertStreet($vSanur, '01', '01', 'Jl. Danau Tamblingan', $now);
        $this->upsertPostalCode('ID', '80228', $bali, $denpasar, $denSel, $vSanur, $now);

        // Badung Districts (Kuta, Mengwi, Abiansemal, Petang, Kuta Selatan, Kuta Utara)
        $kuta = $this->upsertDistrict($badung, '510301', 'Kuta', $now);
        $mengwi = $this->upsertDistrict($badung, '510302', 'Mengwi', $now);
        $abiansemal = $this->upsertDistrict($badung, '510303', 'Abiansemal', $now);
        $petang = $this->upsertDistrict($badung, '510304', 'Petang', $now);
        $kutaSelatan = $this->upsertDistrict($badung, '510305', 'Kuta Selatan', $now);
        $kutaUtara = $this->upsertDistrict($badung, '510306', 'Kuta Utara', $now);

        // Kuta Selatan Villages (Benoa, Jimbaran, Kutuh, Pecatu, Tanjung Benoa, Ungasan)
        $vBenoa = $this->upsertVillage($kutaSelatan, '5103051004', 'Benoa', 'kelurahan', '80361', $now);
        $vTjBenoa = $this->upsertVillage($kutaSelatan, '5103051005', 'Tanjung Benoa', 'kelurahan', '80361', $now);
        $vJimbrn = $this->upsertVillage($kutaSelatan, '5103051006', 'Jimbaran', 'kelurahan', '80361', $now);
        $vKutuh = $this->upsertVillage($kutaSelatan, '5103052001', 'Kutuh', 'desa', '80361', $now);
        $vPecatu = $this->upsertVillage($kutaSelatan, '5103052002', 'Pecatu', 'desa', '80361', $now);
        $vUngasan = $this->upsertVillage($kutaSelatan, '5103052003', 'Ungasan', 'desa', '80361', $now);

        $this->upsertStreet($vBenoa, '01', '01', 'Jl. Bypass Ngurah Rai', $now);
        $this->upsertStreet($vBenoa, '02', '01', 'Jl. Pratama', $now);
        $this->upsertPostalCode('ID', '80361', $bali, $badung, $kutaSelatan, $vBenoa, $now);

        // --- Sumatera Utara ---
        $sumut = $this->upsertProvince('ID', '12', 'Sumatera Utara', $now);
        $medan = $this->upsertRegency($sumut, '1271', 'Kota Medan', 'kota', $now);

        $medanKota = $this->upsertDistrict($medan, '127101', 'Medan Kota', $now);
        $vMesjid = $this->upsertVillage($medanKota, '1271011001', 'Mesjid', 'kelurahan', '20213', $now);
        $this->upsertStreet($vMesjid, '01', '01', 'Jl. Sutoyo', $now);
        $this->upsertPostalCode('ID', '20213', $sumut, $medan, $medanKota, $vMesjid, $now);

        // --- Sisa 30 Provinsi Indonesia (province only) ---
        $this->upsertProvince('ID', '11', 'Aceh', $now);
        $this->upsertProvince('ID', '13', 'Sumatera Barat', $now);
        $this->upsertProvince('ID', '14', 'Riau', $now);
        $this->upsertProvince('ID', '15', 'Jambi', $now);
        $this->upsertProvince('ID', '16', 'Sumatera Selatan', $now);
        $this->upsertProvince('ID', '17', 'Bengkulu', $now);
        $this->upsertProvince('ID', '18', 'Lampung', $now);
        $this->upsertProvince('ID', '19', 'Kepulauan Bangka Belitung', $now);
        $this->upsertProvince('ID', '21', 'Kepulauan Riau', $now);
        $this->upsertProvince('ID', '61', 'Kalimantan Barat', $now);
        $this->upsertProvince('ID', '62', 'Kalimantan Tengah', $now);
        $this->upsertProvince('ID', '63', 'Kalimantan Selatan', $now);
        $this->upsertProvince('ID', '64', 'Kalimantan Timur', $now);
        $this->upsertProvince('ID', '65', 'Kalimantan Utara', $now);
        $this->upsertProvince('ID', '71', 'Sulawesi Utara', $now);
        $this->upsertProvince('ID', '72', 'Sulawesi Tengah', $now);
        $this->upsertProvince('ID', '73', 'Sulawesi Selatan', $now);
        $this->upsertProvince('ID', '74', 'Sulawesi Tenggara', $now);
        $this->upsertProvince('ID', '75', 'Gorontalo', $now);
        $this->upsertProvince('ID', '76', 'Sulawesi Barat', $now);
        $this->upsertProvince('ID', '52', 'Nusa Tenggara Barat', $now);
        $this->upsertProvince('ID', '53', 'Nusa Tenggara Timur', $now);
        $this->upsertProvince('ID', '81', 'Maluku', $now);
        $this->upsertProvince('ID', '82', 'Maluku Utara', $now);
        $this->upsertProvince('ID', '91', 'Papua Barat', $now);
        $this->upsertProvince('ID', '92', 'Papua', $now);
        $this->upsertProvince('ID', '93', 'Papua Selatan', $now);
        $this->upsertProvince('ID', '94', 'Papua Tengah', $now);
        $this->upsertProvince('ID', '95', 'Papua Pegunungan', $now);
        $this->upsertProvince('ID', '96', 'Papua Barat Daya', $now);
    }
}
