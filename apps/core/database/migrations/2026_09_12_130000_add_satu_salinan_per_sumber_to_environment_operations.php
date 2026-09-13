<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Satu produksi hanya boleh sedang disalin oleh satu operasi, dan itu ditegakkan indeks.
 *
 * ## Lubang yang ditutupnya
 *
 * `environment_operations_satu_berjalan` mengizinkan tepat satu operasi berjalan **per
 * environment**, dan itu sudah cukup untuk penyiapan maupun konversi: keduanya bekerja pada
 * environment yang barisnya sudah ada. Penyalinan tidak. Ia melahirkan sasarannya sendiri, jadi dua
 * penyalinan yang berjalan bersamaan dari produksi yang sama menghasilkan **dua sasaran berbeda** —
 * dua baris operasi atas dua environment yang berbeda, dan indeks lama tidak melihat satu pun
 * tabrakan.
 *
 * Yang dirugikan bukan sasarannya melainkan sumbernya. `pg_dump` membaca seluruh isi database
 * produksi; dua di antaranya sekaligus berarti dua kali beban baca, dua kali lalu lintas jaringan,
 * dan dua berkas dump sebesar database itu di disk yang sama — pada jam kerja, di satu server
 * PostgreSQL yang melayani semua pelanggan. Rencananya menyebutnya terang-terangan: "satu salinan
 * pada satu waktu **per environment** — ditegakkan indeks pada `environment_operations`".
 *
 * ## Kenapa indeks, dan bukan pemeriksaan di dalam perintahnya
 *
 * Pemeriksaan "apakah ada salinan lain berjalan" hanya memindahkan balapannya satu baris ke atas.
 * Dua operator yang menekan tombol dalam detik yang sama sama-sama melihat "tidak ada", dan
 * keduanya berjalan. Itu pola yang sudah ditolak `MemegangOperasiLingkungan` untuk kunci
 * operasinya sendiri, dan alasannya tidak berubah di sini.
 *
 * Perintahnya tetap memeriksa lebih dulu — tetapi hanya untuk dua hal yang memang tidak dapat
 * dikerjakan indeks: memberi kalimat yang terbaca manusia, dan mengambil alih operasi yang
 * tenggatnya sudah lewat.
 *
 * ## Kolomnya nullable, dan itu bagian dari rancangannya
 *
 * Hanya penyalinan yang mengisi `source_environment_id`; penyiapan, konversi, dan seluruh operasi
 * lain membiarkannya kosong. PostgreSQL memperlakukan NULL sebagai nilai yang selalu berbeda di
 * dalam unique index, jadi operasi-operasi itu tidak pernah saling menghalangi lewat indeks ini.
 * Predikat `IS NOT NULL` karena itu tidak mengubah perilakunya — ia ditulis supaya orang yang
 * membaca indeks ini tahu bahwa sifat itu memang diandalkan, bukan kebetulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Baris berjalan yang sudah telanjur kembar — kalau ada — akan membuat pembuatan indeks ini
        // gagal, dan kegagalannya menuduh migration padahal sebabnya data. Tidak ada satu pun
        // penyalinan yang pernah berjalan sampai hari ini (perintahnya baru lahir bersama migration
        // ini), jadi tidak ada yang perlu dibereskan lebih dulu. Kalimat ini ada supaya orang
        // berikutnya tahu bahwa itu diperiksa, bukan dilewati.
        DB::statement(
            'CREATE UNIQUE INDEX environment_operations_satu_salinan_per_sumber '
            .'ON environment_operations (source_environment_id) '
            ."WHERE status = 'running' AND source_environment_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS environment_operations_satu_salinan_per_sumber');
    }
};
