<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_country_hierarchy_levels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('country_code', 3);
            $table->unsignedTinyInteger('level');
            $table->string('level_code', 30); // 'province', 'regency', 'district', 'village', 'street', 'building'
            $table->string('level_name', 100); // 'Provinsi', 'Kabupaten/Kota', 'Kecamatan', 'Desa/Kelurahan'
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'level'], 'ref_country_hier_level_unique');
            $table->index(['country_code', 'level_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_country_hierarchy_levels');
    }
};
