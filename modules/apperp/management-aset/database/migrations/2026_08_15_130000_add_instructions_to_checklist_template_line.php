<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instruksi pengerjaan pada baris template checklist.
     *
     * Tabel hasil pemeriksaan work order sudah memiliki kolom `instruksi` sejak awal, tetapi
     * tidak ada tempat menuliskannya: baris template belum menyimpan instruksi apa pun,
     * sehingga kolom itu tidak pernah terisi. Ini menutup sumbernya.
     *
     * Tempatnya di template, sejajar dengan `wajib`, karena instruksi adalah bagian dari
     * prosedur yang disusun sekali di kantor — bukan sesuatu yang diketik teknisi saat
     * sedang memegang kunci pas.
     */
    public function up(): void
    {
        Schema::table('m_maintenance_checklist_template_line', function (Blueprint $table): void {
            $table->text('instruksi')->nullable()->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('m_maintenance_checklist_template_line', function (Blueprint $table): void {
            $table->dropColumn('instruksi');
        });
    }
};
