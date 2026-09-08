<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda wajib pada baris template checklist.
     *
     * Tempatnya di template, bukan di work order, karena "pemeriksaan ini tidak boleh
     * dilewat" adalah keputusan penyusun prosedur di kantor, bukan keputusan teknisi di
     * lapangan. Work order menyalinnya bersama baris lain saat dibuat.
     *
     * Tanpa kolom ini, gate yang menolak menyatakan pekerjaan selesai selama masih ada
     * pemeriksaan wajib yang kosong tidak punya apa pun untuk diperiksa dan menjadi mati.
     *
     * Default `false` supaya baris yang sudah ada tidak tiba-tiba menahan pekerjaan.
     */
    public function up(): void
    {
        Schema::table('m_maintenance_checklist_template_line', function (Blueprint $table): void {
            $table->boolean('wajib')->default(false)->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('m_maintenance_checklist_template_line', function (Blueprint $table): void {
            $table->dropColumn('wajib');
        });
    }
};
