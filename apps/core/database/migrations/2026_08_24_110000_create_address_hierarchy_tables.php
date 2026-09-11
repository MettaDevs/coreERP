<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_countries', function (Blueprint $table): void {
            $table->string('code', 3)->primary(); // ISO-3166-1 alpha-2 or alpha-3 (e.g. 'ID' or 'IDN')
            $table->string('iso3', 3)->nullable();
            $table->string('name', 100);
            $table->string('phone_code', 10)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ref_provinces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->string('country_code', 3);
            $table->string('code', 20);
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('ref_countries')->cascadeOnDelete();
            $table->index(['country_code', 'code']);
        });

        Schema::create('ref_regencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->foreignUlid('province_id')->constrained('ref_provinces')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('type', 20)->default('kabupaten'); // 'kabupaten' | 'kota'
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['province_id', 'code']);
        });

        Schema::create('ref_districts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->foreignUlid('regency_id')->constrained('ref_regencies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['regency_id', 'code']);
        });

        Schema::create('ref_villages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->foreignUlid('district_id')->constrained('ref_districts')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('type', 20)->default('kelurahan'); // 'kelurahan' | 'desa'
            $table->string('postal_code', 10)->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['district_id', 'code']);
        });

        Schema::create('ref_postal_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->string('country_code', 3)->default('ID');
            $table->string('postal_code', 10)->index();
            $table->foreignUlid('province_id')->nullable()->constrained('ref_provinces')->nullOnDelete();
            $table->foreignUlid('regency_id')->nullable()->constrained('ref_regencies')->nullOnDelete();
            $table->foreignUlid('district_id')->nullable()->constrained('ref_districts')->nullOnDelete();
            $table->foreignUlid('village_id')->nullable()->constrained('ref_villages')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('ref_countries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_postal_codes');
        Schema::dropIfExists('ref_villages');
        Schema::dropIfExists('ref_districts');
        Schema::dropIfExists('ref_regencies');
        Schema::dropIfExists('ref_provinces');
        Schema::dropIfExists('ref_countries');
    }
};
