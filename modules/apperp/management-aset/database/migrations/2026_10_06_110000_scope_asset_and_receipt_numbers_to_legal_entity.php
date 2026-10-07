<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_penerimaan_aset', function (Blueprint $table): void {
            $table->dropUnique('aset_tr_penerimaan_aset_tenant_id_kode_unique');
        });
        Schema::table('aset_tr_aset', function (Blueprint $table): void {
            $table->dropUnique('t_aset_tenant_id_kode_unique');
        });

        // Nomor terbit per entitas legal. Nomor yang sama di PT berbeda adalah dua dokumen,
        // sedangkan duplikat dalam PT yang sama tetap ditolak untuk baris yang belum diarsipkan.
        DB::statement('CREATE UNIQUE INDEX aset_tr_penerimaan_kode_unique ON aset_tr_penerimaan_aset (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX aset_tr_aset_kode_unique ON aset_tr_aset (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX aset_tr_penerimaan_kode_unique');
        DB::statement('DROP INDEX aset_tr_aset_kode_unique');
        Schema::table('aset_tr_penerimaan_aset', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'kode'], 'aset_tr_penerimaan_aset_tenant_id_kode_unique');
        });
        Schema::table('aset_tr_aset', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'kode'], 't_aset_tenant_id_kode_unique');
        });
    }
};
