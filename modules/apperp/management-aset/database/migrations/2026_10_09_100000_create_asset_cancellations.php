<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_tr_pembatalan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('resource', 60);
            $table->ulid('document_id');
            $table->ulid('legal_entity_id');
            $table->ulid('responsible_org_unit_id')->nullable();
            $table->integer('source_version')->nullable();
            $table->string('reason', 250);
            $table->date('posting_date');
            $table->string('status', 20)->default('pending');
            $table->string('requested_by_user_id', 60);
            $table->string('acted_by_user_id', 60)->nullable();
            $table->ulid('workflow_instance_id')->nullable();
            $table->jsonb('posting_ids')->nullable();
            $table->text('failure_message')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'resource', 'document_id']);
        });
        AuditColumns::attach('aset_tr_pembatalan');
        DB::statement("CREATE UNIQUE INDEX aset_pembatalan_pending_uq ON aset_tr_pembatalan (tenant_id, resource, document_id) WHERE status = 'pending' AND deleted_at IS NULL");

        Schema::table('aset_tr_penyusutan_aset', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
        });
        // Pembalikan lama juga membebaskan periode untuk dihitung ulang.
        DB::statement('UPDATE aset_tr_penyusutan_aset p SET cancelled_at = r.created_at FROM aset_tr_penyusutan_aset r WHERE r.tenant_id = p.tenant_id AND r.reverses_period_id = p.id');
        DB::statement('DROP INDEX IF EXISTS t_penyusutan_periode_original_unique');
        DB::statement('CREATE UNIQUE INDEX t_penyusutan_periode_original_unique ON aset_tr_penyusutan_aset (tenant_id, buku_aset_id, period_ends_on) WHERE reverses_period_id IS NULL AND cancelled_at IS NULL');
    }

    public function down(): void {}
};
