<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_workers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key');
            $table->string('personnel_number');
            $table->string('name');
            $table->string('email')->nullable();
            $table->ulid('core_membership_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'personnel_number']);
            $table->unique(['tenant_id', 'core_membership_id']);
        });

        Schema::create('hr_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('hr_positions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key');
            $table->string('code');
            $table->string('name');
            $table->ulid('job_id');
            $table->ulid('operating_unit_id');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'creation_key']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'operating_unit_id']);
        });

        Schema::create('hr_worker_position_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('worker_id');
            $table->ulid('position_id');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'position_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_worker_position_assignments');
        Schema::dropIfExists('hr_positions');
        Schema::dropIfExists('hr_jobs');
        Schema::dropIfExists('hr_workers');
    }
};
