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
        Schema::create('aset_m_jenis_aset_kondisi', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('jenis_aset_id');
            $table->ulid('kondisi_aset_id');
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'kondisi_aset_id'], 'aset_m_jenis_aset_kondisi_condition_ix');
            $table->foreign(['tenant_id', 'jenis_aset_id'], 'aset_m_jenis_aset_kondisi_jenis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'kondisi_aset_id'], 'aset_m_jenis_aset_kondisi_condition_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_kondisi_aset')->restrictOnDelete();
        });
        // Relasi yang dilepas diarsipkan, dan pasangan yang sama boleh dipasang lagi sesudahnya.
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_jenis_aset_kondisi_unique '.
            'ON aset_m_jenis_aset_kondisi (tenant_id, jenis_aset_id, kondisi_aset_id) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('aset_m_jenis_aset_kondisi');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_m_jenis_aset_kondisi');
    }
};
