<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aturan validasi yang harus dipenuhi sebelum work order boleh berpindah ke satu
        // status; padanan FastTab `Validate` pada Work order lifecycle state di Dynamics
        // 365 F&O.
        //
        // Dua hal yang membuatnya berbeda dari gate yang digantikannya. Pertama, aturan
        // melekat pada STATUS TUJUAN, bukan pada tipe work order, sehingga pemeriksaan yang
        // sama dapat longgar saat pekerjaan dijadwalkan dan ketat saat dinyatakan selesai.
        // Kedua, aturan memiliki tingkat keparahan: tidak semua kekurangan data layak
        // menghentikan orang yang sedang memegang kunci pas.
        Schema::create('m_validasi_status_work_order', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('status', 30);
            $table->string('aturan', 40);
            $table->boolean('aktif')->default(false);
            // `informasi` hanya dicatat, `peringatan` membiarkan transisi berjalan tetapi
            // tersimpan pada jejak status, `error` menolak transisi.
            $table->string('keparahan', 20)->default('error');
            $table->timestamps();

            $table->unique(['tenant_id', 'status', 'aturan'], 'm_wo_validasi_identity_uq');
        });

        // Peringatan yang dilewati harus meninggalkan jejak. Tanpa ini, "boleh lanjut
        // dengan peringatan" tidak dapat dibedakan dari "semuanya lengkap" ketika riwayat
        // pekerjaan dibaca ulang berbulan-bulan kemudian.
        Schema::table('tr_pemeliharaan_aset_status_log', function (Blueprint $table): void {
            $table->text('peringatan')->nullable()->after('alasan');
        });
    }

    public function down(): void
    {
        Schema::table('tr_pemeliharaan_aset_status_log', function (Blueprint $table): void {
            $table->dropColumn('peringatan');
        });
        Schema::dropIfExists('m_validasi_status_work_order');
    }
};
