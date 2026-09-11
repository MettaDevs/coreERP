<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_streets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->foreignUlid('village_id')->constrained('ref_villages')->cascadeOnDelete();
            $table->string('rt', 5)->nullable();   // Rukun Tetangga
            $table->string('rw', 5)->nullable();   // Rukun Warga
            $table->string('name', 200)->nullable(); // Nama jalan / deskripsi area
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['village_id', 'rt', 'rw']);
        });

        Schema::create('ref_buildings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->foreignUlid('village_id')->constrained('ref_villages')->cascadeOnDelete();
            $table->foreignUlid('street_id')->nullable()->constrained('ref_streets')->nullOnDelete();
            $table->string('name', 200);          // Nama gedung / kompleks / blok
            $table->string('unit', 50)->nullable(); // Nomor unit / kamar
            $table->string('floor', 20)->nullable(); // Lantai
            $table->string('block', 20)->nullable(); // Blok
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['village_id', 'name']);
        });

        Schema::create('ref_address_parameters', function (Blueprint $table): void {
            $table->string('country_code', 3)->primary();
            $table->boolean('use_province')->default(true);
            $table->boolean('use_regency')->default(true);
            $table->boolean('use_district')->default(true);
            $table->boolean('use_village')->default(true);
            $table->boolean('use_rt_rw')->default(true);
            $table->boolean('use_postal_code')->default(true);
            $table->boolean('use_building')->default(true);
            $table->string('address_format', 500)->nullable(); // Format string e.g. "{street}, RT {rt}/RW {rw}, {village}, {district}, {regency}, {province} {postal_code}"
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('ref_countries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_address_parameters');
        Schema::dropIfExists('ref_buildings');
        Schema::dropIfExists('ref_streets');
    }
};
