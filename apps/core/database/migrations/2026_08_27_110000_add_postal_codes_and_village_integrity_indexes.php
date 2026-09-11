<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ref_postal_codes', function (Blueprint $table): void {
            $table->unique(['country_code', 'postal_code', 'village_id'], 'uq_postal_codes_country_code_village');
            $table->index('district_id', 'idx_postal_codes_district_id');
            $table->index('village_id', 'idx_postal_codes_village_id');
        });

        Schema::table('ref_villages', function (Blueprint $table): void {
            $table->index(['district_id', 'code'], 'idx_villages_district_code');
            $table->index(['postal_code'], 'idx_villages_postal_code');
            $table->index(['district_id', 'type'], 'idx_villages_district_type');
        });
    }

    public function down(): void
    {
        Schema::table('ref_postal_codes', function (Blueprint $table): void {
            $table->dropIndex('idx_postal_codes_district_id');
            $table->dropIndex('idx_postal_codes_village_id');
            $table->dropUnique('uq_postal_codes_country_code_village');
        });

        Schema::table('ref_villages', function (Blueprint $table): void {
            $table->dropIndex('idx_villages_district_code');
            $table->dropIndex('idx_villages_postal_code');
            $table->dropIndex('idx_villages_district_type');
        });
    }
};
