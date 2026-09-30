<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran dokumen (area 6 TODO analisa gap BC fase 1, K-09): satu tabel untuk lampiran semua record, padanan
 * `Document Attachment` di Business Central.
 *
 * - Kuncinya jenis record (nama tabel induk), ID record, dan nomor baris dokumen yang opsional, seperti
 *   `Table ID`, `No.`, dan `Line No.` di BC. Tidak ada foreign key ke record induk: induknya bisa tabel module.
 * - Isi berkas di disk (`coreerp.attachments.disk`), bukan di database. `content_hash` adalah SHA-256 isinya
 *   saat diunggah dan diperiksa ulang setiap kali diunduh.
 * - `data_class` adalah klasifikasi isi lampiran, disalin dari jenis record induknya saat diunggah
 *   (lampiran pekerja: data pribadi).
 * - Pelampir dan waktunya adalah kolom jejak (`created_by_user_id`) dan `created_at` (K-01).
 * - Arsip memakai `deleted_at`; berkasnya tetap di disk.
 *
 * Kolom jejak dan trigger ditulis langsung seperti migration retensi, karena admin.erp ikut menjalankan
 * migration Core tanpa kelas `App\`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('record_type', 64);
            $table->string('record_id', 64);
            $table->unsignedInteger('line_number')->nullable();
            $table->string('file_name', 250);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path', 300);
            $table->char('content_hash', 64);
            $table->string('data_class', 40);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['tenant_id', 'record_type', 'record_id']);
        });

        DB::statement('CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON document_attachments FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()');
        DB::statement('CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON document_attachments FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()');
        DB::statement('CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON document_attachments FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
    }
};
