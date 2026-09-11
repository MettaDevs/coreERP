<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_administrative_division_timezones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('division_type', 30); // 'country', 'province', 'regency', 'district', 'village'
            $table->string('division_id', 40);   // Primary key / identifier of division
            $table->string('timezone', 100);    // IANA timezone identifier e.g. 'Asia/Jakarta'
            $table->boolean('is_default')->default(true);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['division_type', 'division_id']);
            $table->unique(['division_type', 'division_id', 'timezone'], 'ref_div_tz_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_administrative_division_timezones');
    }
};
