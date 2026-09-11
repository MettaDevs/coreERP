<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ref_regencies', function (Blueprint $table): void {
            $table->unique(['province_id', 'code'], 'ref_regencies_prov_code_unique');
        });

        Schema::table('ref_districts', function (Blueprint $table): void {
            $table->unique(['regency_id', 'code'], 'ref_districts_reg_code_unique');
        });

        Schema::table('ref_villages', function (Blueprint $table): void {
            $table->unique(['district_id', 'code'], 'ref_villages_dist_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ref_villages', function (Blueprint $table): void {
            $table->dropUnique('ref_villages_dist_code_unique');
        });

        Schema::table('ref_districts', function (Blueprint $table): void {
            $table->dropUnique('ref_districts_reg_code_unique');
        });

        Schema::table('ref_regencies', function (Blueprint $table): void {
            $table->dropUnique('ref_regencies_prov_code_unique');
        });
    }
};
