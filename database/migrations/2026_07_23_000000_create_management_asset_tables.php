<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('asset_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('org_unit_id')->nullable()->index();
            $table->ulid('asset_category_id')->nullable();
            $table->ulid('asset_group_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->foreign('asset_category_id')->references('id')->on('asset_categories')->nullOnDelete();
            $table->foreign('asset_group_id')->references('id')->on('asset_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_groups');
        Schema::dropIfExists('asset_categories');
    }
};
