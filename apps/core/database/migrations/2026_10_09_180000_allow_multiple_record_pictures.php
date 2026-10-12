<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Foto adalah koleksi per record; melepas batas tunggal tidak mengubah atau menghapus berkas yang ada.
        DB::statement('DROP INDEX document_attachments_active_picture_unique');
    }

    public function down(): void
    {
        DB::statement("CREATE UNIQUE INDEX document_attachments_active_picture_unique ON document_attachments (tenant_id, record_type, record_id) WHERE kind = 'picture' AND deleted_at IS NULL");
    }
};
