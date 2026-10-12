<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('record_type', 64);
            $table->string('record_id', 64);
            $table->text('content');
            $table->string('data_class', 40);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->index(['tenant_id', 'record_type', 'record_id']);
        });

        DB::statement('CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON document_notes FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()');
        DB::statement('CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON document_notes FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()');
        DB::statement('CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON document_notes FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_notes');
    }
};
