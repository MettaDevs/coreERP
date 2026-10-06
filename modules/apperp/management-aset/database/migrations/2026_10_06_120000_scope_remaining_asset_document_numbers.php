<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'aset_tr_perencanaan_aset' => ['tr_perencanaan_aset_tenant_id_kode_unique', 'aset_tr_perencanaan_kode_unique'],
        'aset_tr_permintaan_pengadaan_aset' => ['tr_permintaan_pengadaan_aset_tenant_id_kode_unique', 'aset_tr_permintaan_pengadaan_kode_unique'],
        'aset_tr_mutasi_aset' => ['aset_tr_mutasi_aset_tenant_id_kode_unique', 'aset_tr_mutasi_kode_unique'],
        'aset_tr_pemeliharaan_aset' => ['tr_pemeliharaan_aset_tenant_id_kode_unique', 'aset_tr_pemeliharaan_kode_unique'],
        'aset_tr_dokumen_siklus_aset' => ['t_dokumen_siklus_aset_tenant_id_kode_unique', 'aset_tr_siklus_kode_unique'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => [$oldIndex, $newIndex]) {
            Schema::table($tableName, function (Blueprint $table) use ($oldIndex): void {
                $table->dropUnique($oldIndex);
            });
            // Tiga reference berbeda berbagi tabel siklus; jenis dokumen ikut menjadi namespace.
            $columns = $tableName === 'aset_tr_dokumen_siklus_aset'
                ? 'tenant_id, legal_entity_id, jenis_dokumen, kode'
                : 'tenant_id, legal_entity_id, kode';
            DB::statement("CREATE UNIQUE INDEX {$newIndex} ON {$tableName} ({$columns}) WHERE deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => [$oldIndex, $newIndex]) {
            DB::statement("DROP INDEX {$newIndex}");
            Schema::table($tableName, function (Blueprint $table) use ($oldIndex): void {
                $table->unique(['tenant_id', 'kode'], $oldIndex);
            });
        }
    }
};
