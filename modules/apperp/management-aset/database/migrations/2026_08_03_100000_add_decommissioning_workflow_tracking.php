<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_dokumen_siklus_aset', function (Blueprint $table): void {
            $table->ulid('workflow_instance_id')->nullable()->after('status');
            $table->unique(['tenant_id', 'workflow_instance_id']);
        });
        Schema::create('processed_core_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('event_id');
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_core_events');
        Schema::table('tr_dokumen_siklus_aset', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'workflow_instance_id']);
            $table->dropColumn('workflow_instance_id');
        });
    }
};
