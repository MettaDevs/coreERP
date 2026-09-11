<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operasi yang berjalan memperoleh masa berlaku, dan tanpanya kuncinya macet permanen.
 *
 * `environment_operations_satu_berjalan` mengizinkan tepat satu operasi berjalan per environment,
 * dan itu memang yang diinginkan. Yang tidak diperhitungkan saat indeks itu dibuat: satu-satunya
 * strategi pemulihan yang desain ini izinkan adalah **menjalankan ulang perintahnya**. Proses yang
 * mati keras di tengah — OOM, container dibunuh, koneksi putus saat migration — meninggalkan baris
 * berstatus berjalan selamanya, dan indeks yang sama lalu menolak setiap percobaan ulang.
 *
 * Jadi penjaganya berubah menjadi penghalang persis pada keadaan yang paling membutuhkannya, dan
 * satu-satunya jalan keluar operator adalah mengetik UPDATE tangan pada pukul dua pagi.
 *
 * Yang hilang bukan kuncinya melainkan pasangannya: sebuah kunci yang dipegang selamanya bukan
 * kunci, ia kebuntuan. Kolom ini memberi setiap operasi berjalan sebuah tenggat, sehingga percobaan
 * berikutnya dapat menyatakan operasi yang lewat tenggatnya gagal lalu mengambil alih — perbaikan
 * maju, bukan kompensasi, sejalan dengan seluruh rancangan ini.
 *
 * Vendor lain melakukan hal yang sama: Business Central membatalkan update yang lewat jendelanya
 * lalu menjadwalkannya ulang, dan Azure menyebut "tinggalkan alurnya, picu pemulihan manual"
 * sebagai salah satu dari tiga respons kegagalan yang sah. Tidak satu pun membiarkan sebuah operasi
 * berjalan tanpa batas waktu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_operations', function (Blueprint $table): void {
            $table->timestamp('lease_until')->nullable();
        });

        // Baris berjalan yang sudah telanjur ada — kalau ada — diberi tenggat yang sudah lewat,
        // bukan tenggat baru. Ia memang sudah tidak diketahui nasibnya, dan membuatnya dapat
        // diambil alih seketika adalah jawaban yang benar.
        DB::table('environment_operations')
            ->where('status', 'running')
            ->whereNull('lease_until')
            ->update(['lease_until' => now()->subMinute()]);

        // Operasi berjalan wajib membawa tenggatnya. Ditegakkan database, bukan kode, karena jalur
        // yang lupa mengisinya persis jalur yang akan membuat kebuntuannya lahir kembali.
        DB::statement(
            'ALTER TABLE environment_operations ADD CONSTRAINT environment_operations_berjalan_bertenggat '
            ."CHECK (status <> 'running' OR lease_until IS NOT NULL)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE environment_operations DROP CONSTRAINT IF EXISTS environment_operations_berjalan_bertenggat');

        Schema::table('environment_operations', function (Blueprint $table): void {
            $table->dropColumn('lease_until');
        });
    }
};
