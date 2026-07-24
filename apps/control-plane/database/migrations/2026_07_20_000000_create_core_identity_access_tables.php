<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('legal_name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('classification', 30);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('legal_entities', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('company_code', 50);
            $table->char('country_code', 2);
            $table->timestamps();
        });

        Schema::create('operating_units', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->timestamps();
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('system_role', 20);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('apps', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->string('name');
            $table->string('version', 40);
            $table->string('status', 20);
            $table->string('database_name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_app_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('app_id', 80);
            $table->string('status', 20)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->restrictOnDelete();
            $table->unique(['tenant_id', 'app_id']);
        });

        Schema::create('tenant_deployments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('profile', 30);
            $table->string('placement', 120);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique('tenant_id');
            $table->index(['placement', 'status']);
        });

        Schema::create('app_placements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('app_id', 80);
            $table->string('release_version', 40);
            $table->string('profile', 30);
            $table->string('placement', 120);
            $table->string('artifact_status', 30)->default('pending');
            $table->string('migration_status', 30)->default('pending');
            $table->string('runtime_status', 30)->default('pending');
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->restrictOnDelete();
            $table->unique(['app_id', 'placement']);
        });

        Schema::create('app_installations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('app_placement_id')->constrained('app_placements')->cascadeOnDelete();
            $table->string('operation', 20);
            $table->string('release_version', 40);
            $table->string('status', 20);
            $table->string('failure_stage', 30)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['app_placement_id', 'started_at']);
        });

        Schema::create('app_entry_points', function (Blueprint $table) {
            $table->string('code', 160)->primary();
            $table->string('app_id', 80);
            $table->string('name');
            $table->string('type', 30);
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->string('code', 160)->primary();
            $table->string('app_id', 80);
            $table->string('entry_point_code', 160);
            $table->string('access_level', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
            $table->foreign('entry_point_code')->references('code')->on('app_entry_points')->cascadeOnDelete();
        });

        Schema::create('security_privileges', function (Blueprint $table) {
            $table->string('code', 160)->primary();
            $table->string('app_id', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
        });

        Schema::create('security_privilege_permissions', function (Blueprint $table) {
            $table->string('privilege_code', 160);
            $table->string('permission_code', 160);
            $table->foreign('privilege_code')->references('code')->on('security_privileges')->cascadeOnDelete();
            $table->foreign('permission_code')->references('code')->on('permissions')->cascadeOnDelete();
            $table->primary(['privilege_code', 'permission_code']);
        });

        Schema::create('security_duties', function (Blueprint $table) {
            $table->string('code', 160)->primary();
            $table->string('app_id', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
        });

        Schema::create('security_duty_privileges', function (Blueprint $table) {
            $table->string('duty_code', 160);
            $table->string('privilege_code', 160);
            $table->foreign('duty_code')->references('code')->on('security_duties')->cascadeOnDelete();
            $table->foreign('privilege_code')->references('code')->on('security_privileges')->cascadeOnDelete();
            $table->primary(['duty_code', 'privilege_code']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('security_role_duties', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('duty_code', 160);
            $table->foreign('duty_code')->references('code')->on('security_duties')->restrictOnDelete();
            $table->primary(['role_id', 'duty_code']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('active');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'role_id', 'source']);
        });

        Schema::create('invitation_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('system_role', 20);
            $table->ulid('organization_id')->nullable();
            $table->ulid('hierarchy_id')->nullable();
            $table->boolean('include_descendants')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('invitation_role_assignments', function (Blueprint $table) {
            $table->foreignUlid('invitation_id')->constrained('invitation_codes')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['invitation_id', 'role_id']);
        });

        Schema::create('provider_access', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_access');
        Schema::dropIfExists('invitation_role_assignments');
        Schema::dropIfExists('invitation_codes');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('security_role_duties');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('security_duty_privileges');
        Schema::dropIfExists('security_duties');
        Schema::dropIfExists('security_privilege_permissions');
        Schema::dropIfExists('security_privileges');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('app_entry_points');
        Schema::dropIfExists('app_installations');
        Schema::dropIfExists('app_placements');
        Schema::dropIfExists('tenant_deployments');
        Schema::dropIfExists('tenant_app_entitlements');
        Schema::dropIfExists('apps');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('operating_units');
        Schema::dropIfExists('legal_entities');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('clients');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
