<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `apps.database_name` berhenti wajib diisi.
 *
 * Kolom ini sisa rancangan lama ketika setiap app punya database sendiri. Module berjalan
 * di dalam runtime Core dan memakai database yang sama, jadi ia tidak punya nama database
 * untuk disebutkan. Selama kolomnya wajib, setiap pendaftaran module harus menuliskan
 * nilai karangan — dan nilai karangan di kolom wajib adalah cara tercepat membuat orang
 * berikutnya percaya module punya database sendiri.
 *
 * Yang dilonggarkan hanya kewajiban di tingkat skema. Kewajiban untuk app yang benar-benar
 * berjalan sebagai container sendiri tetap ditegakkan di validasi manifest, karena di
 * sanalah perbedaan module dan container diketahui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('database_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris module tidak punya nama database dan tidak boleh dikarang di sini; mundur
        // hanya mungkin bila katalog memang tidak memuat baris seperti itu. Membiarkan
        // migration ini gagal lebih jujur daripada menulis nilai palsu diam-diam.
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('database_name')->nullable(false)->change();
        });
    }
};
