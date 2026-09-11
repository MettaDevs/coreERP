<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequence_profiles', function (Blueprint $table): void {
            $table->string('code', 80)->primary();
            $table->string('name', 150);
            $table->boolean('is_continuous')->default(false);
            $table->boolean('allow_manual')->default(false);
            $table->boolean('preallocation_enabled')->default(true);
            $table->unsignedSmallInteger('preallocation_quantity')->default(20);
            $table->json('segments');
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['code' => 'non-continuous-default', 'name' => 'Nomor otomatis', 'is_continuous' => false, 'allow_manual' => false, 'preallocation_enabled' => true, 'preallocation_quantity' => 20],
            ['code' => 'continuous-strict', 'name' => 'Nomor berkelanjutan', 'is_continuous' => true, 'allow_manual' => false, 'preallocation_enabled' => false, 'preallocation_quantity' => 0],
            ['code' => 'manual-compatible', 'name' => 'Nomor otomatis atau manual', 'is_continuous' => false, 'allow_manual' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 20],
        ] as $profile) {
            DB::table('number_sequence_profiles')->insert([...$profile, 'segments' => json_encode([['type' => 'number', 'length' => 6]], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('app_number_sequence_references', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('app_id', 80);
            $table->string('code', 160)->unique();
            $table->string('name', 150);
            $table->json('allowed_scopes');
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
        });

        Schema::create('tenant_number_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reference_id')->constrained('app_number_sequence_references')->cascadeOnDelete();
            $table->string('profile_code', 80);
            $table->string('scope_type', 30)->default('tenant');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_continuous')->default(false);
            $table->boolean('allow_manual')->default(false);
            $table->boolean('reset_annually')->default(false);
            $table->boolean('preallocation_enabled')->default(true);
            $table->unsignedSmallInteger('preallocation_quantity')->default(20);
            $table->unsignedBigInteger('minimum_number')->default(1);
            $table->unsignedBigInteger('maximum_number')->nullable();
            $table->json('segments');
            $table->timestamps();
            $table->foreign('profile_code')->references('code')->on('number_sequence_profiles')->restrictOnDelete();
            $table->unique(['tenant_id', 'reference_id']);
        });

        Schema::create('number_sequence_counters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sequence_id')->constrained('tenant_number_sequences')->cascadeOnDelete();
            $table->string('scope_key', 180);
            $table->string('period_key', 10)->default('all');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['sequence_id', 'scope_key', 'period_key']);
        });

        Schema::create('number_sequence_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sequence_id')->constrained('tenant_number_sequences')->cascadeOnDelete();
            $table->string('scope_key', 180);
            $table->string('period_key', 10)->default('all');
            $table->unsignedBigInteger('first_number');
            $table->unsignedBigInteger('last_number');
            $table->unsignedBigInteger('next_number');
            $table->timestamps();
            $table->index(['sequence_id', 'scope_key', 'period_key']);
        });

        Schema::create('number_sequence_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sequence_id')->constrained('tenant_number_sequences')->restrictOnDelete();
            $table->string('app_id', 80);
            $table->string('scope_key', 180);
            $table->string('period_key', 10)->default('all');
            $table->unsignedBigInteger('numeric_value');
            $table->string('formatted_value', 255);
            $table->string('idempotency_key', 160);
            $table->string('status', 20)->default('reserved');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->restrictOnDelete();
            $table->unique(['sequence_id', 'app_id', 'idempotency_key']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('number_sequence_reusable_numbers', function (Blueprint $table): void {
            $table->foreignUlid('sequence_id')->constrained('tenant_number_sequences')->cascadeOnDelete();
            $table->string('scope_key', 180);
            $table->string('period_key', 10)->default('all');
            $table->unsignedBigInteger('numeric_value');
            $table->primary(['sequence_id', 'scope_key', 'period_key', 'numeric_value']);
        });

        Schema::create('number_sequence_issues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sequence_id')->constrained('tenant_number_sequences')->restrictOnDelete();
            $table->string('app_id', 80);
            $table->string('scope_key', 180);
            $table->string('period_key', 10)->default('all');
            $table->unsignedBigInteger('numeric_value')->nullable();
            $table->string('formatted_value', 255);
            $table->string('idempotency_key', 160);
            $table->boolean('is_manual')->default(false);
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->restrictOnDelete();
            $table->unique(['sequence_id', 'app_id', 'idempotency_key']);
            $table->unique(['sequence_id', 'scope_key', 'period_key', 'numeric_value']);
            $table->unique(
                ['sequence_id', 'scope_key', 'period_key', 'formatted_value'],
                'number_sequence_issues_formatted_unique',
            );
        });

        Schema::create('number_sequence_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sequence_id')->constrained('tenant_number_sequences')->restrictOnDelete();
            $table->string('app_id', 80)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('formatted_value', 255)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->nullOnDelete();
            $table->index(['sequence_id', 'occurred_at']);
        });

        Schema::create('app_service_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('app_id', 80);
            $table->string('name', 150);
            $table->string('secret_hash');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
            $table->unique(['app_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_service_credentials');
        Schema::dropIfExists('number_sequence_audit_events');
        Schema::dropIfExists('number_sequence_issues');
        Schema::dropIfExists('number_sequence_reusable_numbers');
        Schema::dropIfExists('number_sequence_reservations');
        Schema::dropIfExists('number_sequence_allocations');
        Schema::dropIfExists('number_sequence_counters');
        Schema::dropIfExists('tenant_number_sequences');
        Schema::dropIfExists('app_number_sequence_references');
        Schema::dropIfExists('number_sequence_profiles');
    }
};
