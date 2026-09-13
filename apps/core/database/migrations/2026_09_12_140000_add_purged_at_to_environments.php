<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penghapusan permanen memperoleh jejaknya sendiri, dan barisnya tetap tinggal.
 *
 * ## Kenapa baris `environments`-nya tidak ikut dibuang
 *
 * Dua hal memaksanya, dan keduanya bukan selera.
 *
 * Yang pertama foreign key: `environment_operations.environment_id` menunjuk tabel ini dengan
 * `restrictOnDelete`, dan komentar migration aslinya menyebutkan alasannya terang-terangan —
 * riwayat sebuah lingkungan yang dihapus tidak boleh ikut lenyap bersamanya, justru riwayat itu
 * yang dibutuhkan untuk memahami kenapa ia dihapus. Menghapus barisnya berarti lebih dulu
 * menghapus riwayatnya, yaitu membuang persis yang paling dibutuhkan.
 *
 * Yang kedua aturan repo ini sendiri: penghapusan fisik baris dilarang. Yang benar-benar dibuang
 * `environment:hapus-permanen` adalah **databasenya** — di situlah data pelanggan berada, dan
 * itulah yang memakan disk. Barisnya tinggal sebagai nisan: siapa pemiliknya, kapan ia
 * kedaluwarsa, kapan ia dihapus lunak, dan kapan isinya benar-benar dibuang.
 *
 * Jadi yang dibutuhkan bukan `DELETE` melainkan satu kolom yang membedakan "dihapus lunak, isinya
 * masih ada" dari "isinya sudah tidak ada di mana pun". Tanpa kolom itu keduanya terlihat sama,
 * dan `environment:pulihkan` akan dengan senang hati memulihkan baris yang databasenya sudah
 * lenyap — lingkungan yang berstatus aktif, tampil di daftar, lalu gagal pada permintaan pertama
 * dengan `database "..." does not exist`.
 *
 * ## Kenapa `database_name` justru TIDAK dikosongkan saat dibuang
 *
 * Menggoda, dan salah. Pada tabel ini `database_name` yang kosong punya arti yang sudah terpasang
 * sejak awal: **ikut database koneksi bawaan** — keadaan pooled dan on-prem. Mengosongkannya pada
 * baris yang databasenya baru saja dibuang mengubah artinya menjadi kebalikan dari yang
 * sebenarnya: ia jadi terbaca menunjuk database pusat. Nama yang tetap tinggal justru berguna;
 * ia yang menjawab "database mana yang dulu dipakai lingkungan ini" ketika seseorang menelusuri
 * sisa berkas atau cadangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->timestamp('purged_at')->nullable();
        });

        // Dibuang permanen tanpa pernah dihapus lunak berarti melewati masa tenggang — yaitu
        // melewati satu-satunya jendela tempat sebuah kesalahan masih dapat dibatalkan. Urutannya
        // ditegakkan di sini supaya jalur mana pun yang kelak lupa melewatinya tetap ditolak.
        DB::statement('ALTER TABLE environments ADD CONSTRAINT environments_buang_sesudah_hapus_lunak CHECK (purged_at IS NULL OR deleted_at IS NOT NULL)');

        // "Produksi tidak pernah dihapus keras" dinyatakan di sini, bukan hanya di dalam perintah.
        //
        // Perintahnya memang menolaknya lebih dulu dan dengan kalimat yang terbaca, tetapi
        // penjagaan yang hanya hidup di kode adalah penjagaan yang hilang pada jalur berikutnya
        // yang lupa memanggilnya — layar operator, skrip pembersihan yang ditulis buru-buru, atau
        // satu UPDATE tangan pada pukul dua pagi. Yang dijaga di sini kerusakan yang tidak dapat
        // dibatalkan sama sekali, jadi ia layak dijaga dua kali.
        DB::statement("ALTER TABLE environments ADD CONSTRAINT environments_produksi_tidak_dibuang CHECK (purged_at IS NULL OR kind <> 'production')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE environments DROP CONSTRAINT IF EXISTS environments_produksi_tidak_dibuang');
        DB::statement('ALTER TABLE environments DROP CONSTRAINT IF EXISTS environments_buang_sesudah_hapus_lunak');

        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('purged_at');
        });
    }
};
