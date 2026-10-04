<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dasbor, widget, dan query tersimpan engine analitik (area 6). Rancangannya di
 * docs/todo/analitik/dasbor-dan-visual.md bagian *Tabel*, aturan berbaginya di docs/todo/analitik/keamanan.md.
 *
 * - `analytics_dashboards`: milik satu pengguna (`user_id`). `shared` menandai dasbor yang terlihat oleh setiap
 *   pemegang hak melihat dasbor di tenant. Nama dasbor pribadi unik per pemiliknya, nama dasbor bersama unik
 *   per tenant. `layout` hanya menyimpan letak yang pernah diatur pengguna (`[{widget_id, x, y, w, h}]`).
 * - `analytics_widgets`: satu tile, grafik, tabel, atau teks di satu dasbor, dengan query JSON-nya.
 * - `analytics_saved_queries`: query bernama dari penjelajah. `code` unik per tenant karena publikasi dan feed
 *   (fase 2) menunjuknya dengan kode itu.
 *
 * Kode dataset bukan foreign key: dataset hidup di kode module dan dapat hilang bersama module yang dicabut,
 * sedangkan widget-nya tidak ikut dihapus (tidak ada baris yang dihapus fisik). `dataset_version` mencatat
 * versi dataset saat query disimpan, supaya kunci yang kemudian diganti nama dapat dipetakan saat dibaca.
 *
 * Kolom jejak dan trigger ditulis langsung seperti migration preset laporan, karena admin.erp ikut
 * menjalankan migration Core tanpa kelas aplikasi.
 */
return new class extends Migration
{
    private const TABLES = ['analytics_dashboards', 'analytics_widgets', 'analytics_saved_queries'];

    public function up(): void
    {
        Schema::create('analytics_dashboards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->boolean('shared')->default(false);
            $table->jsonb('layout')->default('[]');
            $table->jsonb('slicers')->default('[]');
            $table->string('template_code', 160)->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['tenant_id', 'shared']);
            $table->index(['tenant_id', 'user_id']);
        });
        DB::statement('CREATE UNIQUE INDEX analytics_dashboards_owner_name_unique ON analytics_dashboards (tenant_id, user_id, lower(name)) WHERE deleted_at IS NULL AND NOT shared');
        DB::statement('CREATE UNIQUE INDEX analytics_dashboards_shared_name_unique ON analytics_dashboards (tenant_id, lower(name)) WHERE deleted_at IS NULL AND shared');

        Schema::create('analytics_widgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->foreignUlid('dashboard_id')->constrained('analytics_dashboards');
            $table->string('title', 120);
            $table->string('type', 20);
            $table->string('dataset_code', 160)->nullable();
            $table->unsignedInteger('dataset_version')->nullable();
            $table->jsonb('query')->nullable();
            $table->jsonb('visual')->default('{}');
            $table->unsignedInteger('cache_ttl_seconds')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['tenant_id', 'dashboard_id']);
            $table->index(['tenant_id', 'dataset_code']);
        });

        Schema::create('analytics_saved_queries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('code', 80);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('shared')->default(false);
            $table->string('dataset_code', 160);
            $table->unsignedInteger('dataset_version');
            $table->jsonb('query');
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['tenant_id', 'user_id']);
        });
        DB::statement('CREATE UNIQUE INDEX analytics_saved_queries_code_unique ON analytics_saved_queries (tenant_id, lower(code)) WHERE deleted_at IS NULL');

        foreach (self::TABLES as $table) {
            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
            DB::statement("CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()");
            DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
