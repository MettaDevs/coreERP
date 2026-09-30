<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opsi dan filter laporan per pengguna (K-24, K-25), padanan tabel `Object Options` Business Central yang
 * menyimpan "Last used options and filters" dan setelan laporan bernama (page 1560 Report Settings).
 *
 * - `report_last_used_options`: satu baris per pengguna per laporan, ditimpa setiap kali laporan dijalankan.
 *   Halaman filter laporan dan dialog cetak membukanya sebagai isian awal.
 * - `report_presets`: preset bernama milik satu pengguna. `shared` menandai preset yang terlihat oleh semua
 *   pengguna tenant yang boleh menjalankan laporannya. Nilai tanggal boleh berupa token relatif
 *   (`@this_month.start`) yang diterjemahkan saat preset dipakai, menurut zona pengguna.
 *
 * Kode laporan bukan foreign key ke `app_reports`: laporan dapat hilang bersama module yang dicabut, dan
 * opsinya tidak ikut dihapus (tidak ada baris yang dihapus fisik).
 *
 * `report_exports.kind` membedakan ekspor ber-layout, "Excel (data saja)" (K-26), dan ekspor daftar di layar
 * (K-27). Kolom baru dengan nilai bawaan: kode rilis sebelumnya tetap membaca dan menulis tabel itu.
 *
 * Kolom jejak dan trigger ditulis langsung seperti migration lampiran, karena admin.erp ikut menjalankan
 * migration Core tanpa kelas `App\`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_last_used_options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('report_code', 160);
            $table->jsonb('parameters');
            $table->string('format', 10)->nullable();
            $table->string('layout_ref', 60)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        DB::statement('CREATE UNIQUE INDEX report_last_used_options_user_report_unique ON report_last_used_options (tenant_id, user_id, report_code) WHERE deleted_at IS NULL');

        Schema::create('report_presets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('report_code', 160);
            $table->string('name', 80);
            $table->boolean('shared')->default(false);
            $table->jsonb('parameters');
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['tenant_id', 'report_code']);
        });
        DB::statement('CREATE UNIQUE INDEX report_presets_owner_name_unique ON report_presets (tenant_id, report_code, user_id, lower(name)) WHERE deleted_at IS NULL');

        foreach (['report_last_used_options', 'report_presets'] as $table) {
            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
            DB::statement("CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()");
            DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()");
        }

        Schema::table('report_exports', function (Blueprint $table): void {
            $table->string('kind', 20)->default('layout');
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('report_presets');
        Schema::dropIfExists('report_last_used_options');
    }
};
