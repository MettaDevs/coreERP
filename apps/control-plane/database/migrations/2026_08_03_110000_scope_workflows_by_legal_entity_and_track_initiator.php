<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_configurations', function (Blueprint $table): void {
            $table->ulid('legal_entity_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'workflow_type_id', 'legal_entity_id'], 'workflow_configuration_scope_index');
        });
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->ulid('initiator_membership_id')->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_instances', fn (Blueprint $table) => $table->dropColumn('initiator_membership_id'));
        Schema::table('workflow_configurations', function (Blueprint $table): void {
            $table->dropIndex('workflow_configuration_scope_index');
            $table->dropColumn('legal_entity_id');
        });
    }
};
