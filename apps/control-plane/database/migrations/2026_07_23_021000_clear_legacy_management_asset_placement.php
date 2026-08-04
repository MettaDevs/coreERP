<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_placements')->where('app_id', 'management-aset')->delete();
    }

    public function down(): void
    {
        // Artifact eksternal harus melalui deployment registry, bukan dipulihkan dari folder transisi.
    }
};
