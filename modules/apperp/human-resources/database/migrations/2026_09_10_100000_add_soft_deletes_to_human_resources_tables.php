<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seluruh tabel module ini memakai soft delete.
 *
 * Ini keputusan pemilik produk, bukan preferensi teknis, dan ia menyentuh keempat tabel tanpa
 * kecuali. Alasannya sama di semuanya: baris di sini saling menunjuk — penugasan menunjuk
 * pekerja dan posisi, posisi menunjuk jabatan — sehingga penghapusan fisik satu baris
 * meninggalkan penunjuk yang tidak menunjuk apa pun, dan riwayat penugasan tahun lalu
 * berhenti bisa dibaca.
 *
 * Ditulis sebagai migration tersendiri, bukan sebagai perubahan pada migration pembuatan
 * tabelnya. Migration itu sudah pernah berjalan pada database app lama; mengubahnya berarti
 * dua database yang mengaku menjalankan migration yang sama padahal isinya berbeda.
 *
 * Tidak ada baris yang ikut terhapus karena ini: kolomnya lahir `null`, dan `null` berarti
 * belum dihapus.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABEL = ['hr_workers', 'hr_jobs', 'hr_positions', 'hr_worker_position_assignments'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
