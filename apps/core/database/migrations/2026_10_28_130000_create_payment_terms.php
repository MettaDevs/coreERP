<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Migration Core juga dijalankan admin.erp tanpa autoload kelas Core. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('code', 50);
            $table->string('name', 250);
            $table->unsignedSmallInteger('due_days')->default(0);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->string('creation_key', 154)->nullable();
            $table->string('creation_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
        });

        DB::statement('CREATE UNIQUE INDEX payment_terms_active_code_unique ON payment_terms (tenant_id, code) WHERE deleted_at IS NULL');
        DB::statement('CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON payment_terms FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()');
        DB::statement('CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON payment_terms FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()');
        DB::statement('CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON payment_terms FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
