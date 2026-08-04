<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_data_policies', function (Blueprint $table): void {
            $table->string('code', 160)->primary();
            $table->string('app_id', 80);
            $table->string('name', 150);
            $table->json('protected_permissions');
            $table->boolean('requires_legal_entity')->default(false);
            $table->boolean('requires_operating_unit')->default(false);
            $table->boolean('allows_descendants')->default(false);
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
        });

        Schema::create('role_assignment_data_policy_scopes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->foreignUlid('role_assignment_id')->constrained('role_assignments')->cascadeOnDelete();
            $table->string('policy_code', 160);
            $table->ulid('legal_entity_id')->nullable();
            $table->ulid('organization_id')->nullable();
            $table->ulid('hierarchy_id')->nullable();
            $table->ulid('hierarchy_version_id')->nullable();
            $table->boolean('include_descendants')->default(false);
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->index(['role_assignment_id', 'policy_code']);
            $table->index(['tenant_id', 'policy_code', 'valid_from']);
            $table->foreign('policy_code')->references('code')->on('app_data_policies')->restrictOnDelete();
            $table->foreign('legal_entity_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('hierarchy_id')->references('id')->on('organization_hierarchies')->restrictOnDelete();
            $table->foreign('hierarchy_version_id')->references('id')->on('organization_hierarchy_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignment_data_policy_scopes');
        Schema::dropIfExists('app_data_policies');
    }
};
