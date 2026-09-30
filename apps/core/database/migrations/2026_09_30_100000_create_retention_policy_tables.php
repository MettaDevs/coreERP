<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layanan retensi data log (area 4 TODO analisa gap BC fase 1, K-14 sampai K-16), padanan Retention Policy
 * Setup dan Retention Policy Log Entry di Business Central.
 *
 * - `retention_policy_setups`: pilihan tenant per kebijakan. Tenant tanpa baris memakai bawaan yang ditulis
 *   di kode; kode kebijakannya dikenal `RetentionPolicies`, bukan foreign key.
 * - `retention_policy_log_entries`: hasil tiap penerapan per tenant dan kebijakan, hanya bila ada baris
 *   terhapus atau penghapusan gagal. Tabel ini sendiri terdaftar untuk retensi.
 *
 * Kedua tabel tenant membawa kolom jejak dan trigger seperti tabel tenant Core lain (ditulis langsung
 * karena admin.erp ikut menjalankan migration Core, jadi tanpa kelas `App\`). Indeks `(tenant_id, changed_at)`
 * pada `change_log_entries` menjaga penghapusan retensi tidak memindai riwayat tenant lain.
 */
return new class extends Migration
{
    private const TABLES = ['retention_policy_setups', 'retention_policy_log_entries'];

    public function up(): void
    {
        Schema::create('retention_policy_setups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('policy_code', 60);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('retention_days')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();

            $table->unique(['tenant_id', 'policy_code']);
        });

        Schema::create('retention_policy_log_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('policy_code', 60);
            $table->unsignedBigInteger('deleted_count')->default(0);
            $table->timestampTz('cutoff_at');
            $table->string('status', 12);
            $table->string('message', 300)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE retention_policy_log_entries ADD CONSTRAINT retention_policy_log_entries_status_check CHECK (status IN ('success', 'failed'))");

        DB::statement('CREATE INDEX change_log_entries_retention ON change_log_entries (tenant_id, changed_at)');

        foreach (self::TABLES as $table) {
            DB::statement("CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()");
            DB::statement("CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS change_log_entries_retention');
        Schema::dropIfExists('retention_policy_log_entries');
        Schema::dropIfExists('retention_policy_setups');
    }
};
