<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hash kata sandi sementara hanya boleh ada di operasi situs yang masih terbuka.
 *
 * Operasi `install` membawa `admin_password_hash` — hash bcrypt kata sandi sementara owner tenant di
 * server klien (`docs/todo/pasang-satu-perintah`, PS-03). Agen membutuhkannya hanya selama pemasangan.
 * Sesudah operasinya berhasil, gagal, kedaluwarsa, atau dibatalkan, hash itu tidak dibutuhkan siapa
 * pun; yang tersisa hanya kemungkinan dibocorkan lewat cadangan database, salinan, atau pembaca yang
 * tidak seharusnya — dan hash bcrypt kata sandi 20 karakter tetap sesuatu yang dapat dicoba dipecahkan.
 *
 * ## Kenapa constraint, padahal konsol sudah membuangnya
 *
 * Penutup operasi ada banyak: laporan langkah agen, tenggat yang habis, permintaan yang kedaluwarsa,
 * pembatalan operator, pencabutan situs, penghentian sewa, dan perintah pasang yang menggantikan
 * pendahulunya. Setiap penutup baru adalah kesempatan lupa membuang hash, dan lupanya tidak terlihat
 * di mana pun — barisnya tetap tampak benar. Dengan constraint ini, penutup yang lupa **gagal menulis**,
 * dan kegagalan itu tertangkap test sebelum sampai ke produksi.
 *
 * Tabel ini juga ada, kosong, di setiap database lingkungan dan di server klien karena migration dibagi;
 * di sana constraint-nya tidak menolak apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `unprepared`, bukan `statement`: operator jsonb `?` dibaca PDO sebagai tempat parameter, dan
        // pernyataan tanpa binding akan ditolak sebelum sampai ke PostgreSQL.
        DB::unprepared(
            'ALTER TABLE site_operations ADD CONSTRAINT site_operations_hash_sandi_hanya_saat_terbuka '
            ."CHECK (status IN ('requested', 'running') OR NOT (parameters ? 'admin_password_hash'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_operations DROP CONSTRAINT IF EXISTS site_operations_hash_sandi_hanya_saat_terbuka');
    }
};
