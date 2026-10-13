<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE aset_m_lokasi_aset DROP CONSTRAINT m_lokasi_aset_tenant_id_kode_unique');
        DB::statement('CREATE UNIQUE INDEX m_lokasi_aset_tenant_id_kode_unique ON aset_m_lokasi_aset (tenant_id, kode) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX m_lokasi_aset_tenant_id_kode_unique');
        DB::statement('ALTER TABLE aset_m_lokasi_aset ADD CONSTRAINT m_lokasi_aset_tenant_id_kode_unique UNIQUE (tenant_id, kode)');
    }
};
