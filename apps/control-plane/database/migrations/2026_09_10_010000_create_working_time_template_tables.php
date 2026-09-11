<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_time_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('legal_entity_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'legal_entity_id']);
        });

        Schema::create('working_time_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('working_time_template_id')->constrained('working_time_templates')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Senin, 1=Selasa, 2=Rabu, 3=Kamis, 4=Jumat, 5=Sabtu, 6=Minggu
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            $table->decimal('efficiency', 5, 2)->default(100.00);
            $table->string('property', 50)->nullable();
            $table->boolean('closed_for_pickup')->default(false);
            $table->decimal('hours', 5, 2)->default(0.00);
            $table->timestamps();
            $table->index(['working_time_template_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_time_lines');
        Schema::dropIfExists('working_time_templates');
    }
};
