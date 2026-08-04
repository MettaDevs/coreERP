<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NumberSequenceProfileSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $number = [['type' => 'number', 'length' => 6]];

        foreach ([
            ['code' => 'non-continuous-default', 'name' => 'Nomor otomatis', 'is_continuous' => false, 'allow_manual' => false, 'preallocation_enabled' => true, 'preallocation_quantity' => 20, 'segments' => $number],
            ['code' => 'continuous-strict', 'name' => 'Nomor berkelanjutan', 'is_continuous' => true, 'allow_manual' => false, 'preallocation_enabled' => true, 'preallocation_quantity' => 5, 'segments' => $number],
            ['code' => 'manual-compatible', 'name' => 'Nomor otomatis atau manual', 'is_continuous' => false, 'allow_manual' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 20, 'segments' => $number],
        ] as $profile) {
            DB::table('number_sequence_profiles')->updateOrInsert(['code' => $profile['code']], [...$profile, 'segments' => json_encode($profile['segments'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
