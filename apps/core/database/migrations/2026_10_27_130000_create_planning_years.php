<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            AuditColumns::add($table);
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'planning_years_company_id_unique');
            $table->unique(['tenant_id', 'creation_key']);
            $table->foreign(['tenant_id', 'legal_entity_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX planning_years_active_year_unique ON planning_years (tenant_id, legal_entity_id, year) WHERE deleted_at IS NULL');
        AuditColumns::attach('planning_years');
    }

    public function down(): void {}
};
