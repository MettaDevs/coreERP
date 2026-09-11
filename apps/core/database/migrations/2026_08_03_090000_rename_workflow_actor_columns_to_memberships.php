<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_work_items', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'assigned_user_id', 'status']);
            $table->renameColumn('assigned_user_id', 'assigned_membership_id');
            $table->index(['tenant_id', 'assigned_membership_id', 'status'], 'workflow_work_items_inbox_index');
        });

        Schema::table('workflow_history', function (Blueprint $table): void {
            $table->renameColumn('actor_user_id', 'actor_membership_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_history', function (Blueprint $table): void {
            $table->renameColumn('actor_membership_id', 'actor_user_id');
        });

        Schema::table('workflow_work_items', function (Blueprint $table): void {
            $table->dropIndex('workflow_work_items_inbox_index');
            $table->renameColumn('assigned_membership_id', 'assigned_user_id');
            $table->index(['tenant_id', 'assigned_user_id', 'status']);
        });
    }
};
