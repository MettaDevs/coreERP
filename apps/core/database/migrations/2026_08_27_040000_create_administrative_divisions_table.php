<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_administrative_divisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('country_id', 3)->index();
            $table->ulid('parent_id')->nullable()->index();
            $table->unsignedTinyInteger('level')->index(); // 1=province, 2=regency/city, 3=district, 4=village
            $table->string('type', 30)->index(); // 'province' | 'regency' | 'city' | 'district' | 'village'
            $table->string('official_code', 50)->index();
            $table->string('name', 255);
            $table->string('status', 20)->default('active')->index();
            $table->json('lineage')->nullable();
            $table->timestamps();

            $table->foreign('country_id')->references('code')->on('ref_countries')->cascadeOnDelete();
            $table->unique(['country_id', 'level', 'official_code'], 'unique_country_level_code');
        });

        Schema::table('ref_administrative_divisions', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('ref_administrative_divisions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_administrative_divisions');
    }
};
