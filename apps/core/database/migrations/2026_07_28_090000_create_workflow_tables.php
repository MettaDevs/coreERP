<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('app_id', 80);
            $table->string('code', 120)->unique();
            $table->string('name', 160);
            $table->json('decision_context_schema');
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->restrictOnDelete();
        });

        Schema::create('workflow_configurations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('workflow_type_id');
            $table->string('name', 160);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'workflow_type_id', 'name']);
            $table->foreign('workflow_type_id')->references('id')->on('workflow_types')->restrictOnDelete();
        });

        Schema::create('workflow_configuration_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('configuration_id');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->date('effective_from')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->ulid('published_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['configuration_id', 'version']);
            $table->foreign('configuration_id')->references('id')->on('workflow_configurations')->cascadeOnDelete();
        });

        Schema::create('workflow_elements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('version_id');
            $table->string('key', 100);
            $table->string('kind', 30);
            $table->string('label', 160);
            $table->json('configuration')->default('{}');
            $table->unsignedInteger('position_x')->default(0);
            $table->unsignedInteger('position_y')->default(0);
            $table->timestamps();
            $table->unique(['version_id', 'key']);
            $table->foreign('version_id')->references('id')->on('workflow_configuration_versions')->cascadeOnDelete();
        });

        Schema::create('workflow_transitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('version_id');
            $table->ulid('from_element_id');
            $table->ulid('to_element_id');
            $table->string('outcome', 40)->nullable();
            $table->json('condition')->nullable();
            $table->timestamps();
            $table->unique(['version_id', 'from_element_id', 'to_element_id', 'outcome']);
            $table->foreign('version_id')->references('id')->on('workflow_configuration_versions')->cascadeOnDelete();
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('workflow_type_id');
            $table->ulid('configuration_version_id');
            $table->string('source_document_type', 120);
            $table->string('source_document_id', 26);
            $table->string('idempotency_key', 160);
            $table->json('decision_context');
            $table->string('status', 30)->default('submitted');
            $table->timestamps();
            $table->unique(['tenant_id', 'workflow_type_id', 'idempotency_key']);
            $table->foreign('workflow_type_id')->references('id')->on('workflow_types')->restrictOnDelete();
            $table->foreign('configuration_version_id')->references('id')->on('workflow_configuration_versions')->restrictOnDelete();
        });

        Schema::create('workflow_work_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('instance_id');
            $table->ulid('element_id');
            $table->ulid('assigned_user_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'assigned_user_id', 'status']);
            $table->foreign('instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
        });

        Schema::create('workflow_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('instance_id');
            $table->string('event_type', 60);
            $table->ulid('actor_user_id')->nullable();
            $table->json('details')->default('{}');
            $table->timestamp('occurred_at');
            $table->foreign('instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['workflow_history', 'workflow_work_items', 'workflow_instances', 'workflow_transitions', 'workflow_elements', 'workflow_configuration_versions', 'workflow_configurations', 'workflow_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
