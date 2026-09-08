<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX t_penyusutan_periode_original_unique ON t_penyusutan_periode (tenant_id, asset_book_id, period_ends_on) WHERE reverses_period_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS t_penyusutan_periode_original_unique');
    }
};
