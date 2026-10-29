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
        Schema::create('planning_years', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('legal_entity_id');
            $table->unsignedSmallInteger('year');
            $table->boolean('active')->default(true);
            $table->string('creation_key', 154)->nullable();
            $table->string('creation_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'planning_years_company_id_unique');
            $table->unique(['tenant_id', 'creation_key']);
            $table->foreign(['tenant_id', 'legal_entity_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX planning_years_active_year_unique ON planning_years (tenant_id, legal_entity_id, year) WHERE deleted_at IS NULL');
        DB::statement('CREATE OR REPLACE TRIGGER stamp_audit_actor BEFORE INSERT OR UPDATE ON planning_years FOR EACH ROW EXECUTE FUNCTION coreerp_stamp_audit_actor()');
        DB::statement('CREATE OR REPLACE TRIGGER bump_row_version BEFORE UPDATE ON planning_years FOR EACH ROW EXECUTE FUNCTION coreerp_bump_row_version()');
        DB::statement('CREATE OR REPLACE TRIGGER log_change AFTER INSERT OR UPDATE OR DELETE ON planning_years FOR EACH ROW EXECUTE FUNCTION coreerp_log_change()');
    }

    public function down(): void {}
};
