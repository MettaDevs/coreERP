<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'aset_m_pabrikan_aset', 'aset_m_jenis_aset', 'aset_m_group_aset', 'aset_m_tipe_atribut',
        'aset_m_maintenance_job_type', 'aset_m_maintenance_checklist_variable',
        'aset_m_maintenance_checklist_template', 'aset_m_rencana_pemeliharaan', 'aset_m_polis_asuransi',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'creation_payload_hash')) {
                continue;
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->char('creation_payload_hash', 64)->nullable());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('creation_payload_hash'));
        }
    }
};
