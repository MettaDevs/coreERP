<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tr_penerimaan_aset', 'nama')) {
            Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
                $table->string('nama', 150)->nullable()->after('kode');
            });
        }

        // Aset lama mendapat nama yang dapat dibaca. Model paling spesifik, lalu
        // jenis aset, dan terakhir kode aset bila referensi lamanya tidak lengkap.
        DB::statement(<<<'SQL'
            UPDATE tr_penerimaan_aset AS aset
            SET nama = COALESCE(
                NULLIF(CONCAT_WS(' ',
                    (SELECT nama FROM m_pabrikan_aset WHERE tenant_id = aset.tenant_id AND id = aset.pabrikan_aset_id),
                    (SELECT nama FROM m_model_aset WHERE tenant_id = aset.tenant_id AND id = aset.model_aset_id)
                ), ''),
                (SELECT nama FROM m_jenis_aset WHERE tenant_id = aset.tenant_id AND id = aset.jenis_aset_id),
                'Aset ' || aset.kode
            )
            WHERE aset.nama IS NULL
        SQL);

        DB::table('tr_penerimaan_aset')->whereNull('nama')->update(['nama' => DB::raw("'Aset ' || kode")]);
        // Cabang SQLite dibuang pada F3-16; hanya ada satu mesin database sekarang.
        DB::statement('ALTER TABLE tr_penerimaan_aset ALTER COLUMN nama SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
            $table->dropColumn('nama');
        });
    }
};
