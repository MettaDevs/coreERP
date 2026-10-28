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
        Schema::create('funding_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('code', 50);
            $table->string('name', 250);
            $table->boolean('active')->default(true);
            $table->string('creation_key', 154)->nullable();
            $table->string('creation_payload_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            AuditColumns::add($table);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
        });
        DB::statement('CREATE UNIQUE INDEX funding_sources_active_code_unique ON funding_sources (tenant_id, code) WHERE deleted_at IS NULL');
        AuditColumns::attach('funding_sources');
    }

    public function down(): void {}
};
