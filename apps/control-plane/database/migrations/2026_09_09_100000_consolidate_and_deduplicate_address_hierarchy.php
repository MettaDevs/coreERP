<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Consolidate and deduplicate address hierarchy data:
     * - Make ISO 3166-1 alpha-2 canonical for ref_countries (249 countries total).
     * - Remap ref_streets from dummy villages to authentic Kemendagri villages.
     * - Remove dummy postal codes and dummy 2-letter provinces.
     * - Deduplicate provinces in ITA, GBR, ARG, CHE, MEX.
     * - Deduplicate districts and villages in Indonesia (Kemendagri standard).
     * - Re-point all child tables (administrative divisions, postal codes, provinces, hierarchy levels)
     *   to the 2-letter country codes.
     * - Remove 3-letter country duplicate rows from ref_countries.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            // 1. Ensure foreign key lookups are fast during cascade
            DB::statement('CREATE INDEX IF NOT EXISTS idx_postal_codes_province_id ON ref_postal_codes (province_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_postal_codes_regency_id ON ref_postal_codes (regency_id)');

            // 2. Remap ref_streets to authentic Kemendagri villages under IDN before deleting dummy data
            DB::statement("
                UPDATE ref_streets s
                SET village_id = v_new.id
                FROM ref_villages v_old,
                LATERAL (
                    SELECT v.id
                    FROM ref_villages v
                    JOIN ref_districts d ON v.district_id = d.id
                    JOIN ref_regencies r ON d.regency_id = r.id
                    JOIN ref_provinces p ON r.province_id = p.id
                    WHERE p.country_code = 'IDN'
                      AND lower(trim(v.name)) = lower(trim(v_old.name))
                    LIMIT 1
                ) v_new
                WHERE s.village_id = v_old.id
                  AND v_old.district_id IN (
                      SELECT d2.id FROM ref_districts d2
                      JOIN ref_regencies r2 ON d2.regency_id = r2.id
                      JOIN ref_provinces p2 ON r2.province_id = p2.id
                      WHERE p2.country_code = 'ID'
                  )
            ");

            // 3. Delete dummy postal codes and dummy 2-letter provinces
            DB::statement('DELETE FROM ref_postal_codes WHERE length(country_code) = 2');
            DB::statement('DELETE FROM ref_provinces WHERE length(country_code) = 2');

            // 4. Deduplicate provinces in ITA, GBR, ARG, CHE, MEX
            DB::statement('
                WITH ranked_provinces AS (
                    SELECT id, country_code, name,
                           ROW_NUMBER() OVER (
                               PARTITION BY country_code, name 
                               ORDER BY (SELECT count(*) FROM ref_regencies WHERE province_id = ref_provinces.id) DESC, 
                                        length(code) ASC, 
                                        id ASC
                           ) as rn
                    FROM ref_provinces
                    WHERE (country_code, name) IN (
                        SELECT country_code, name 
                        FROM ref_provinces 
                        GROUP BY country_code, name 
                        HAVING count(*) > 1
                    )
                ),
                keeper AS (
                    SELECT id, country_code, name FROM ranked_provinces WHERE rn = 1
                ),
                dupe AS (
                    SELECT id, country_code, name FROM ranked_provinces WHERE rn > 1
                )
                UPDATE ref_regencies r
                SET province_id = k.id
                FROM dupe d
                JOIN keeper k ON d.country_code = k.country_code AND d.name = k.name
                WHERE r.province_id = d.id
                  AND NOT EXISTS (
                      SELECT 1 FROM ref_regencies r2 
                      WHERE r2.province_id = k.id AND (r2.code = r.code OR lower(r2.name) = lower(r.name))
                  )
            ');

            DB::statement('
                WITH ranked_provinces AS (
                    SELECT id, country_code, name,
                           ROW_NUMBER() OVER (
                               PARTITION BY country_code, name 
                               ORDER BY (SELECT count(*) FROM ref_regencies WHERE province_id = ref_provinces.id) DESC, 
                                        length(code) ASC, 
                                        id ASC
                           ) as rn
                    FROM ref_provinces
                    WHERE (country_code, name) IN (
                        SELECT country_code, name 
                        FROM ref_provinces 
                        GROUP BY country_code, name 
                        HAVING count(*) > 1
                    )
                )
                DELETE FROM ref_regencies 
                WHERE province_id IN (SELECT id FROM ranked_provinces WHERE rn > 1)
            ');

            DB::statement('
                WITH ranked_provinces AS (
                    SELECT id, country_code, name,
                           ROW_NUMBER() OVER (
                               PARTITION BY country_code, name 
                               ORDER BY (SELECT count(*) FROM ref_regencies WHERE province_id = ref_provinces.id) DESC, 
                                        length(code) ASC, 
                                        id ASC
                           ) as rn
                    FROM ref_provinces
                    WHERE (country_code, name) IN (
                        SELECT country_code, name 
                        FROM ref_provinces 
                        GROUP BY country_code, name 
                        HAVING count(*) > 1
                    )
                )
                DELETE FROM ref_provinces 
                WHERE id IN (SELECT id FROM ranked_provinces WHERE rn > 1)
            ');

            // 5. Correct duplicate district names in Indonesia (Kemendagri standard)
            DB::statement("DELETE FROM ref_villages WHERE code IN ('3171021001', '3171021002')");
            DB::statement("UPDATE ref_districts SET name = 'Sawah Besar' WHERE code = '317102'");
            DB::statement("UPDATE ref_districts SET name = 'Kemayoran' WHERE code = '317103'");

            DB::statement("DELETE FROM ref_villages WHERE code IN ('3273011001', '3273011002', '3273011003')");
            DB::statement("UPDATE ref_districts SET name = 'Sukasari' WHERE code = '327301'");
            DB::statement("UPDATE ref_districts SET name = 'Coblong' WHERE code = '327302'");

            DB::statement("DELETE FROM ref_villages WHERE code IN ('3578011001', '3578011002')");
            DB::statement("UPDATE ref_districts SET name = 'Karang Pilang' WHERE code = '357801'");

            DB::statement("DELETE FROM ref_villages WHERE code = '3471011001'");
            DB::statement("UPDATE ref_districts SET name = 'Tegalrejo' WHERE code = '347101'");

            // 6. Deduplicate villages in Indonesia
            DB::statement("
                DELETE FROM ref_villages WHERE id IN (
                    '01M10DSQ7CJAHP59789SGGE224',
                    '01M10DSQ7CJAHP59789SGGE223',
                    '01M10DRJ1GDRD9FE4C2ATXM5EX',
                    '01M10DSNTGZFM4W0ZHR45SFWBG',
                    '01M10DP6H5QX0QCHA9XT0ETTNM'
                )
            ");

            DB::statement("
                UPDATE ref_villages
                SET name = name || ' (Kelurahan)'
                WHERE code LIKE '%10__'
                  AND (district_id, name) IN (
                      SELECT district_id, name FROM ref_villages GROUP BY district_id, name HAVING count(*) > 1
                  )
            ");

            DB::statement("
                UPDATE ref_villages
                SET name = name || ' (Desa)'
                WHERE (code LIKE '%20__' OR code LIKE '%20___')
                  AND (district_id, name) IN (
                      SELECT district_id, name FROM ref_villages GROUP BY district_id, name HAVING count(*) > 1
                  )
            ");

            DB::statement("
                WITH dupes AS (
                    SELECT id, district_id, name,
                           ROW_NUMBER() OVER (PARTITION BY district_id, name ORDER BY code ASC) as rn
                    FROM ref_villages
                    WHERE (district_id, name) IN (
                        SELECT district_id, name FROM ref_villages GROUP BY district_id, name HAVING count(*) > 1
                    )
                )
                UPDATE ref_villages v
                SET name = v.name || ' (' || v.code || ')'
                FROM dupes d
                WHERE v.id = d.id AND d.rn > 1
            ");

            // 7. Re-point child references to 2-letter canonical country codes
            DB::statement('
                UPDATE ref_administrative_divisions ad 
                SET country_id = c2.code 
                FROM ref_countries c2 
                WHERE ad.country_id = c2.iso3 AND length(c2.code) = 2
            ');

            DB::statement('
                UPDATE ref_postal_codes pc 
                SET country_code = c2.code 
                FROM ref_countries c2 
                WHERE pc.country_code = c2.iso3 AND length(c2.code) = 2
            ');

            DB::statement('
                UPDATE ref_provinces p 
                SET country_code = c2.code 
                FROM ref_countries c2 
                WHERE p.country_code = c2.iso3 AND length(c2.code) = 2
            ');

            DB::statement('
                DELETE FROM ref_country_hierarchy_levels hl3
                USING ref_countries c2, ref_country_hierarchy_levels hl2
                WHERE length(hl3.country_code) = 3
                  AND c2.iso3 = hl3.country_code
                  AND length(c2.code) = 2
                  AND hl2.country_code = c2.code
                  AND hl2.level = hl3.level
            ');

            DB::statement('
                UPDATE ref_country_hierarchy_levels hl3
                SET country_code = c2.code
                FROM ref_countries c2
                WHERE hl3.country_code = c2.iso3 
                  AND length(hl3.country_code) = 3 
                  AND length(c2.code) = 2
            ');

            DB::statement('DELETE FROM ref_address_parameters WHERE length(country_code) = 3');
            DB::statement('DELETE FROM time_zones WHERE length(country_code) = 3');

            DB::statement("DELETE FROM ref_administrative_division_timezones WHERE division_type = 'country' AND length(division_id) = 3");
            DB::statement("DELETE FROM ref_administrative_division_timezones WHERE division_type = 'province' AND division_id NOT IN (SELECT id FROM ref_provinces)");

            // 8. Delete 3-letter country entries from ref_countries
            DB::statement('DELETE FROM ref_countries WHERE length(code) = 3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reference data consolidation is one-way to preserve integrity
    }
};
