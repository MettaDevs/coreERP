<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_administrative_division_external_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('division_id')->index();
            $table->string('system', 50)->index(); // 'KEMENDAGRI', 'BPS', 'ISO', 'POS', etc.
            $table->string('external_code', 100);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('division_id')->references('id')->on('ref_administrative_divisions')->cascadeOnDelete();
            $table->unique(['division_id', 'system', 'external_code'], 'unique_division_sys_code');
        });

        Schema::create('ref_administrative_division_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('division_id')->index();
            $table->string('locale', 10)->index(); // 'id', 'en', etc.
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('division_id')->references('id')->on('ref_administrative_divisions')->cascadeOnDelete();
            $table->unique(['division_id', 'locale'], 'unique_division_locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_administrative_division_translations');
        Schema::dropIfExists('ref_administrative_division_external_codes');
    }
};
