<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('hierarchy_purposes')
            ->where('code', 'establishment')
            ->update([
                'name' => 'Enterprise establishment structure',
                'description' => 'Struktur operating unit yang berperan sebagai establishment di bawah legal entity.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('hierarchy_purposes')
            ->where('code', 'establishment')
            ->update([
                'name' => 'Establishment',
                'description' => 'Struktur unit kerja dan lokasi operasional.',
                'updated_at' => now(),
            ]);
    }
};
