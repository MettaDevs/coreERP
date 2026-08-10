<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_buku_penyusutan', function (Blueprint $table): void {
            // F&O menyimpan nominal kelipatan pembulatan pada Book. Nol berarti
            // perhitungan memakai nominal asli sampai dua desimal.
            $table->decimal('round_off_depreciation', 18, 2)->default(0)->after('export_to_backoffice');
        });

        Schema::table('m_group_buku_penyusutan', function (Blueprint $table): void {
            // Null berarti memakai nilai Book; nol berarti sengaja mematikan
            // pembulatan untuk kombinasi group dan book ini.
            $table->decimal('round_off_depreciation', 18, 2)->nullable()->after('depreciate');
        });

        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            // Snapshot aturan saat aset diterima agar perubahan matriks tidak
            // mengubah buku aset yang sudah berjalan.
            $table->decimal('round_off_depreciation', 18, 2)->default(0)->after('depreciate');
        });
    }

    public function down(): void
    {
        Schema::table('tr_buku_aset', function (Blueprint $table): void {
            $table->dropColumn('round_off_depreciation');
        });

        Schema::table('m_group_buku_penyusutan', function (Blueprint $table): void {
            $table->dropColumn('round_off_depreciation');
        });

        Schema::table('m_buku_penyusutan', function (Blueprint $table): void {
            $table->dropColumn('round_off_depreciation');
        });
    }
};
