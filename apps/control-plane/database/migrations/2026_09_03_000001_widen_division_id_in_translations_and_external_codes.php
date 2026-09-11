<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ref_administrative_division_translations DROP CONSTRAINT IF EXISTS ref_administrative_division_translations_division_id_foreign;');
            DB::statement('ALTER TABLE ref_administrative_division_translations ALTER COLUMN division_id TYPE VARCHAR(50);');

            DB::statement('ALTER TABLE ref_administrative_division_external_codes DROP CONSTRAINT IF EXISTS ref_administrative_division_external_codes_division_id_foreign;');
            DB::statement('ALTER TABLE ref_administrative_division_external_codes ALTER COLUMN division_id TYPE VARCHAR(50);');
        }
    }

    public function down(): void
    {
        // Polymorphic division reference - rollback does not require re-adding restrictive foreign key
    }
};
