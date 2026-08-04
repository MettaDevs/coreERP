<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_calendars', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('fiscal_calendar_id')->constrained('fiscal_calendars')->cascadeOnDelete();
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->unique(['fiscal_calendar_id', 'name']);
            $table->index(['fiscal_calendar_id', 'starts_on', 'ends_on']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordinal');
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'ordinal']);
            $table->index(['fiscal_year_id', 'starts_on', 'ends_on']);
        });

        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->foreignUlid('fiscal_calendar_id')->nullable()->constrained('fiscal_calendars')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('legal_entities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fiscal_calendar_id');
        });
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('fiscal_calendars');
    }
};
