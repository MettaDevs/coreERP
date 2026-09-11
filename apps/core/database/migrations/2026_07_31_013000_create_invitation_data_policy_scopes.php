<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_data_policy_scopes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invitation_id')->constrained('invitation_codes')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('policy_code');
            $table->foreignUlid('legal_entity_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('hierarchy_id')->nullable()->constrained('organization_hierarchies')->restrictOnDelete();
            $table->foreignUlid('hierarchy_version_id')->nullable()->constrained('organization_hierarchy_versions')->restrictOnDelete();
            $table->boolean('include_descendants')->default(false);
            $table->timestamps();
            $table->foreign('policy_code')->references('code')->on('app_data_policies')->restrictOnDelete();
            $table->index(['invitation_id', 'role_id', 'policy_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_data_policy_scopes');
    }
};
