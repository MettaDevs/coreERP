<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cache hasil dan catatan query engine analitik (area 9, `docs/todo/analitik/kinerja-dan-uji-beban.md`
 * bagian *Cache* dan `docs/todo/analitik/keamanan.md` bagian *Log query*).
 *
 * - `analytics_query_cache`: hasil query yang sudah dihitung, dikompres gzip, satu baris per kunci cache.
 *   Tinggal di database tenant, **bukan** di cache store Laravel: store itu dipaku ke database pusat, dan
 *   angka tenant yang punya database sendiri tidak boleh tersalin ke sana (KA-18). Kuncinya sha256 dari
 *   tenant, definisi dataset, query normal, sidik jari jangkauan, zona waktu, dan tanggal hari ini; kolom
 *   lain hanya untuk pembersihan dan diagnosis. Baris kedaluwarsa dihapus fisik saat terbaca atau saat
 *   tenant yang sama menulis — isinya salinan hasil hitung, bukan data bisnis.
 * - `analytics_query_log`: satu baris per query yang sampai ke engine. Nilai saringan pada field data
 *   pribadi sudah disamarkan sebelum ditulis. Diretensi lewat kebijakan `analytics_query_log` (bawaan 90
 *   hari, minimum 7, PQ-05).
 *
 * Kedua tabel tenant membawa kolom jejak, versi baris, dan trigger seperti tabel tenant Core lain, ditulis
 * langsung tanpa kelas `App\` karena admin.erp ikut menjalankan migration Core. Indeks `(tenant_id,
 * expires_at)` menjaga pembersihan cache tidak memindai baris tenant lain; `(tenant_id, created_at)` sama
 * untuk retensi log.
 */
return new class extends Migration
{
    private const TABLES = ['analytics_query_cache', 'analytics_query_log'];

    public function up(): void
    {
        Schema::create('analytics_query_cache', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->char('cache_key', 64);
            $table->string('dataset_code', 160);
            $table->binary('payload');
            $table->unsignedInteger('size_bytes');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->unique(['tenant_id', 'cache_key']);
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('analytics_query_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('principal', 120);
            $table->string('source', 20);
            $table->string('dataset_code', 160);
            $table->unsignedInteger('dataset_version')->nullable();
            $table->char('query_hash', 64);
            $table->jsonb('query');
            $table->string('status', 12);
            $table->string('error_code', 60)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('row_count')->nullable();
            $table->boolean('truncated')->default(false);
            $table->boolean('cached')->default(false);
            $table->timestamps();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE analytics_query_log ADD CONSTRAINT analytics_query_log_status_check CHECK (status IN ('success', 'failed'))");

        foreach (self::TABLES as $table) {
            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
            DB::statement("CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()");
            DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_query_log');
        Schema::dropIfExists('analytics_query_cache');
    }
};
