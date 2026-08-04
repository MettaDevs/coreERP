<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->string('source_reference')->nullable()->after('source');
            $table->unique(['membership_id', 'role_id', 'source', 'source_reference'], 'role_assignment_source_unique');
        });
        Schema::create('automatic_role_assignment_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('role_id');
            $table->ulid('position_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'role_id', 'position_id']);
        });
        Schema::create('access_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('membership_id')->nullable();
            $table->string('action', 100);
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audit_events');
        Schema::dropIfExists('automatic_role_assignment_rules');
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->dropUnique('role_assignment_source_unique');
            $table->dropColumn('source_reference');
        });
    }
};
