<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX t_penyusutan_periode_reversal_unique ON t_penyusutan_periode (tenant_id, reverses_period_id) WHERE reverses_period_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS t_penyusutan_periode_reversal_unique');
    }
};
