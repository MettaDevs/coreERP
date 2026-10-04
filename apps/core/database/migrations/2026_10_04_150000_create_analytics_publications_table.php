<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publikasi analitik (area 15): query tersimpan yang sengaja dibuka pengguna tenant untuk dibaca sistem di luar
 * CoreERP lewat klien integrasi. Rancangannya di docs/todo/analitik/akses-luar.md bagian *Tabel*, aturan
 * keamanannya di docs/todo/analitik/keamanan.md.
 *
 * Bentuknya mengikuti rancangan, dengan empat kolom tambahan yang alasannya ditulis di akses-luar.md:
 *
 * - `dataset_code`, `dataset_version`, `query`: salinan query tersimpan saat dipublikasikan. Yang dibaca sistem
 *   luar adalah salinan yang sudah ditinjau pemiliknya, bukan query tersimpan yang dapat diubah orang lain
 *   kemudian; perubahan query tersimpan baru berlaku setelah pemilik publikasi menerapkannya.
 * - `timezone`: zona waktu periode dan rentang relatif, diambil dari zona pembuatnya saat dibuat, supaya angka
 *   "bulan ini" tidak bergeser mengikuti zona server.
 *
 * `code` unik per tenant untuk baris hidup dan tidak berubah sesudah dibuat, karena ia bagian alamat yang
 * dipakai sistem luar. `owner_user_id` adalah pengguna yang jangkauannya dipakai setiap kali publikasi dibaca;
 * hak dan hibahnya diperiksa ulang pada setiap permintaan. `locked_filters` berbentuk `{dataset: {field: nilai}}`.
 * `embed_origins` dan `embed_parameters` milik publikasi berjenis dasbor (area 17) dan belum diisi area ini.
 *
 * `last_used_at` adalah kolom aktivitas mesin: diperbarui saat publikasi dibaca, jadi tidak menaikkan versi
 * baris, seperti `integration_clients.last_used_at`. Tanpa kelas `App\` karena admin.erp ikut menjalankan
 * migration Core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('code', 80);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('kind', 20)->default('query');
            $table->ulid('saved_query_id')->nullable();
            $table->ulid('dashboard_id')->nullable();
            $table->string('dataset_code', 160)->nullable();
            $table->unsignedInteger('dataset_version')->nullable();
            $table->jsonb('query')->nullable();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('timezone', 64);
            $table->jsonb('locked_filters')->default('{}');
            $table->jsonb('client_ids')->default('[]');
            $table->jsonb('formats')->default('["json"]');
            $table->jsonb('embed_origins')->default('[]');
            $table->jsonb('embed_parameters')->default('[]');
            $table->unsignedInteger('min_group_size')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_user_id']);
            $table->index(['tenant_id', 'saved_query_id']);
        });
        DB::statement('CREATE UNIQUE INDEX analytics_publications_code_unique ON analytics_publications (tenant_id, lower(code)) WHERE deleted_at IS NULL');

        DB::statement('CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON analytics_publications FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()');
        DB::statement("CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON analytics_publications FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version('last_used_at', 'updated_at')");
        DB::statement('CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON analytics_publications FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()');
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_publications');
    }
};
