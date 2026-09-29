<?php

namespace Tests\Feature\ControlPlane;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Penggabungan tabel negara di atas data yang ditanam seeder lama: satu tingkat hierarki tersimpan dua kali,
 * sekali ber-ISO3 dan sekali ber-ISO2. Database dev berisi 282 kembar seperti ini, dan migrasinya berhenti
 * pada `ref_country_hier_level_unique` sebelum kembar ISO3 dibuang lebih dulu (29 September 2026).
 */
class CountryRegionTwinLevelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_merge_drops_three_letter_twins_instead_of_colliding_with_them(): void
    {
        /** @var Migration $merge */
        $merge = require database_path('migrations/2026_09_29_120000_merge_ref_countries_into_country_regions.php');
        $merge->down();

        DB::table('ref_country_hierarchy_levels')->where('country_code', 'AG')->delete();
        foreach (['AG', 'ATG'] as $code) {
            DB::table('ref_country_hierarchy_levels')->insert([
                'id' => strtoupper((string) Str::ulid()), 'country_code' => $code, 'level' => 4,
                'level_code' => 'village', 'level_name' => 'Parish', 'description' => 'Tingkat 4',
            ]);
        }
        // Kode ISO3 tanpa kembar tetap dipetakan, bukan dibuang.
        DB::table('ref_country_hierarchy_levels')->where('country_code', 'AD')->delete();
        DB::table('ref_country_hierarchy_levels')->insert([
            'id' => strtoupper((string) Str::ulid()), 'country_code' => 'AND', 'level' => 4,
            'level_code' => 'village', 'level_name' => 'Parish', 'description' => 'Tingkat 4',
        ]);

        $merge->up();

        $this->assertSame(1, DB::table('ref_country_hierarchy_levels')->where('country_code', 'AG')->where('level', 4)->count());
        $this->assertSame(1, DB::table('ref_country_hierarchy_levels')->where('country_code', 'AD')->where('level', 4)->count());
        $this->assertSame(0, DB::table('ref_country_hierarchy_levels')->whereRaw('length(trim(country_code)) = 3')->count());
    }
}
