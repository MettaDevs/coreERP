<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Memensiunkan `wajib_sebab` dan `wajib_tindakan` dari tipe work order.
     *
     * Keduanya digantikan aturan validasi per status. Menempelkan kewajiban pada tipe
     * membuat pemeriksaan yang sama berlaku sama kerasnya di setiap langkah; menempelkannya
     * pada status tujuan memungkinkan sebab kerusakan menjadi peringatan saat pekerjaan
     * dinyatakan selesai dan penghalang saat dokumen ditutup.
     *
     * `satu_pekerja` tetap tinggal karena ia bukan aturan isi data melainkan batasan bentuk
     * penugasan, dan memang milik tipe pekerjaan.
     */
    public function up(): void
    {
        Schema::table('m_tipe_work_order', function (Blueprint $table): void {
            $table->dropColumn(['wajib_sebab', 'wajib_tindakan']);
        });
    }

    public function down(): void
    {
        Schema::table('m_tipe_work_order', function (Blueprint $table): void {
            $table->boolean('wajib_sebab')->default(false);
            $table->boolean('wajib_tindakan')->default(false);
        });
    }
};
