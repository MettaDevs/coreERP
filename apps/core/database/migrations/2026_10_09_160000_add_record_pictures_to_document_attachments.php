<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->string('kind', 16)->default('document');
        });
        DB::statement("ALTER TABLE document_attachments ADD CONSTRAINT document_attachments_kind_check CHECK (kind IN ('document', 'picture') AND (kind <> 'picture' OR line_number IS NULL))");
        DB::statement("CREATE UNIQUE INDEX document_attachments_active_picture_unique ON document_attachments (tenant_id, record_type, record_id) WHERE kind = 'picture' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX document_attachments_active_picture_unique');
        DB::statement('ALTER TABLE document_attachments DROP CONSTRAINT document_attachments_kind_check');
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
