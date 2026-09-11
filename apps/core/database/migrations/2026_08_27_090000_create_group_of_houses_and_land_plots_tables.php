<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ref_group_of_houses')) {
            Schema::create('ref_group_of_houses', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('tenant_id', 36)->nullable()->index();
                $table->foreignUlid('village_id')->constrained('ref_villages')->cascadeOnDelete();
                $table->string('code', 30)->nullable();
                $table->string('name', 200);
                $table->string('status', 20)->default('active');
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['village_id', 'name']);
            });
        }

        if (! Schema::hasTable('ref_land_plots')) {
            Schema::create('ref_land_plots', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('tenant_id', 36)->nullable()->index();
                $table->foreignUlid('village_id')->constrained('ref_villages')->cascadeOnDelete();
                $table->foreignUlid('street_id')->nullable()->constrained('ref_streets')->nullOnDelete();
                $table->foreignUlid('group_of_houses_id')->nullable()->constrained('ref_group_of_houses')->nullOnDelete();
                $table->string('plot_number', 50);
                $table->string('name', 200)->nullable();
                $table->string('status', 20)->default('active');
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['village_id', 'plot_number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_land_plots');
        Schema::dropIfExists('ref_group_of_houses');
    }
};
