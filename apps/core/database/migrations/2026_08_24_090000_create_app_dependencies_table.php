<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_dependencies', function (Blueprint $table) {
            $table->string('app_id', 80);
            $table->string('depends_on_app_id', 80);
            $table->string('version_range', 40);
            $table->timestamps();

            $table->primary(['app_id', 'depends_on_app_id']);
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
            $table->foreign('depends_on_app_id')->references('id')->on('apps')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_dependencies');
    }
};
