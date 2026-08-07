<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carry a correlation id from the app request through to the published event.
     *
     * A workflow decision arrives days after the request that started it, so the
     * correlation cannot live on the request alone -- it has to be persisted on the
     * instance and copied onto the outbox row when the decision is emitted.
     *
     * `legal_entity_id` joins it because both are envelope fields required by
     * docs/dev/04-api-and-integration.md, and making consumers migrate twice for one
     * envelope change would be gratuitous.
     */
    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->ulid('correlation_id')->nullable()->after('initiator_membership_id');
            $table->index(['tenant_id', 'correlation_id'], 'workflow_instance_correlation_index');
        });

        // Instances that predate this column were their own root: nothing upstream was
        // ever recorded, so the instance id is the truthful correlation rather than a
        // synthetic value that implies a chain which was never captured.
        DB::table('workflow_instances')->whereNull('correlation_id')->update([
            'correlation_id' => DB::raw('id'),
        ]);

        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->ulid('correlation_id')->nullable()->after('tenant_id');
            $table->ulid('legal_entity_id')->nullable()->after('correlation_id');
            $table->index(['tenant_id', 'correlation_id'], 'outbox_event_correlation_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropIndex('outbox_event_correlation_index');
            $table->dropColumn(['correlation_id', 'legal_entity_id']);
        });
        Schema::table('workflow_instances', function (Blueprint $table): void {
            $table->dropIndex('workflow_instance_correlation_index');
            $table->dropColumn('correlation_id');
        });
    }
};
