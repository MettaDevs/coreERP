<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('uom_classes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('uom_systems', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('uom_class_id');
            $table->ulid('uom_system_id')->nullable();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('symbol', 30)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'uom_class_id'])->references(['tenant_id', 'id'])->on('uom_classes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'uom_system_id'])->references(['tenant_id', 'id'])->on('uom_systems')->nullOnDelete();
        });
        Schema::create('uom_translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('unit_id');
            $table->string('locale', 20);
            $table->string('name', 150);
            $table->string('description', 2000)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'unit_id', 'locale']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units_of_measure')->cascadeOnDelete();
        });
        Schema::create('uom_external_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('unit_id');
            $table->string('scheme', 80);
            $table->string('code', 80);
            $table->timestamps();
            $table->unique(['tenant_id', 'scheme', 'code']);
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('units_of_measure')->cascadeOnDelete();
        });
        Schema::create('uom_conversions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('from_unit_id');
            $table->ulid('to_unit_id');
            $table->decimal('factor', 28, 12);
            $table->decimal('offset', 28, 12)->default(0);
            $table->unsignedTinyInteger('rounding_scale')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'from_unit_id', 'to_unit_id']);
            $table->foreign(['tenant_id', 'from_unit_id'])->references(['tenant_id', 'id'])->on('units_of_measure')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'to_unit_id'])->references(['tenant_id', 'id'])->on('units_of_measure')->cascadeOnDelete();
        });
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('type', 120)->index();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('uom_conversions');
        Schema::dropIfExists('uom_external_codes');
        Schema::dropIfExists('uom_translations');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('uom_systems');
        Schema::dropIfExists('uom_classes');
    }
};
