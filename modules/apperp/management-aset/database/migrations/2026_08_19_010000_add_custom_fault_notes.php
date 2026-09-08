<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_sebab_kerusakan', function (Blueprint $table): void {
            $table->boolean('minta_keterangan')->default(false);
        });
        Schema::table('m_tindakan_perbaikan', function (Blueprint $table): void {
            $table->boolean('minta_keterangan')->default(false);
        });
        Schema::table('tr_pemeliharaan_aset_details', function (Blueprint $table): void {
            $table->text('sebab_kerusakan_keterangan')->nullable();
            $table->text('tindakan_perbaikan_keterangan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tr_pemeliharaan_aset_details', function (Blueprint $table): void {
            $table->dropColumn(['sebab_kerusakan_keterangan', 'tindakan_perbaikan_keterangan']);
        });
        Schema::table('m_sebab_kerusakan', function (Blueprint $table): void {
            $table->dropColumn('minta_keterangan');
        });
        Schema::table('m_tindakan_perbaikan', function (Blueprint $table): void {
            $table->dropColumn('minta_keterangan');
        });
    }
};
