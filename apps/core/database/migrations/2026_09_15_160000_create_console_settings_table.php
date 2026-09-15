<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setelan konsol operator yang diubah dari layar, bukan dari berkas `.env`.
 *
 * Yang tinggal di sini adalah setelan yang tidak cocok di `.env` karena alasan yang sama: ia diubah
 * operator dari layar Pengaturan, bukan oleh orang yang memegang akses ke server. Contohnya:
 *
 * - **Pasangan kunci lisensi.** Dibuat konsol sendiri saat pertama dibutuhkan. Lewat berkas, ia
 *   menuntut orang membuat kunci dengan `openssl`, menaruhnya di jalur yang benar, dan memasangnya
 *   ke container — tiga langkah yang masing-masing sudah pernah terlupa.
 * - **Rahasia robot sistem Harbor** yang dipakai konsol untuk menerbitkan kredensial per situs
 *   (`docs/todo/registry-harbor`).
 *
 * Nilai rahasia disimpan terenkripsi `Crypt`, jadi isi tabel ini yang bocor tanpa `APP_KEY` tidak
 * menandatangani atau membuka apa pun. Setiap perubahannya diaudit.
 *
 * Kuncinya teks, bukan kolom per setelan: setelan bertambah lebih cepat daripada migration layak
 * ditulis, dan tiap nilainya dibaca satu per satu menurut namanya.
 *
 * Tabel milik sisi pusat. `updated_by` menunjuk `users` — keduanya di sisi yang sama, jadi foreign key
 * ini tidak menyeberang batas — dan dikosongkan, bukan ikut terhapus, bila penggunanya hilang: nilai
 * setelan tidak menjadi tidak sah karena orang yang terakhir mengubahnya pergi.
 *
 * Tanpa `created_at`. Yang ditanyakan orang tentang sebuah setelan adalah kapan dan oleh siapa ia
 * terakhir diubah; riwayat lengkapnya di `operator_audit_events`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->text('value');
            $table->timestamp('updated_at')->useCurrent();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_settings');
    }
};
