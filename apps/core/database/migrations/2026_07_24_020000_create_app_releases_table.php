<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('app_id', 80);
            $table->string('version', 40);
            $table->char('manifest_sha256', 64);
            $table->string('api_image', 500);
            $table->string('ui_image', 500);
            $table->string('bundle_path', 255);
            $table->string('compose_file', 120);
            $table->string('compose_project', 120);
            $table->string('api_service', 120);
            $table->string('ui_service', 120);
            $table->string('database_service', 120);
            $table->string('status', 20)->default('available');
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('apps')->restrictOnDelete();
            $table->unique(['app_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
