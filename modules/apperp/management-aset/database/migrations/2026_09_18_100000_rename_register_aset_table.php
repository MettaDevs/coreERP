<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Mengembalikan nama register aset: `aset_tr_penerimaan_aset` menjadi `aset_tr_aset`.
 *
 * **Kenapa namanya salah selama ini.** Tabel ini lahir sebagai `t_aset` — daftar aset itu
 * sendiri, satu baris satu aset, yang umurnya selama aset dipakai. Pada
 * `2026_07_30_120000_rename_transaction_tables.php` ia ikut disapu menjadi
 * `tr_penerimaan_aset` karena saat itu satu-satunya cara aset masuk ke sistem adalah
 * dengan mengisi layar penerimaan. Yang dinamai jadi prosesnya, bukan bendanya.
 *
 * Penerimaan sekarang benar-benar menjadi dokumen tersendiri: satu dokumen bisa
 * melahirkan dua puluh aset sekaligus, dan dokumennya sendiri perlu tabel. Jadi nama
 * `aset_tr_penerimaan_aset` harus dikembalikan kepada yang berhak memakainya, dan
 * registernya memakai nama bendanya.
 *
 * **Kunci asing ikut sendiri.** PostgreSQL menempelkan kendala pada tabelnya, bukan pada
 * namanya, jadi `ALTER TABLE ... RENAME TO` tidak memutus satu pun dari sepuluh kunci
 * asing yang menunjuk ke sini. Nama kendala dan nama indeks memang tetap menyebut ejaan
 * lama; itu dibiarkan karena menggantinya hanya mengubah tulisan yang tidak dibaca siapa
 * pun di produk, sementara setiap penggantian nama kendala adalah kunci tabel tambahan
 * pada database yang sudah besar.
 *
 * Penjaganya `hasTable` di kedua sisi supaya perintah pemasangan aman diulang.
 *
 * @kontrak Modul ini belum terpasang di satu pun klien on-prem, jadi tidak ada rilis
 * sebelumnya yang membaca nama tabel lama di server mana pun. Sesudah klien pertama
 * terpasang, penggantian nama tabel wajib menempuh expand/contract.
 */
return new class extends Migration
{
    private const LAMA = 'aset_tr_penerimaan_aset';

    private const BARU = 'aset_tr_aset';

    public function up(): void
    {
        if (Schema::hasTable(self::LAMA) && ! Schema::hasTable(self::BARU)) {
            Schema::rename(self::LAMA, self::BARU);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::BARU) && ! Schema::hasTable(self::LAMA)) {
            Schema::rename(self::BARU, self::LAMA);
        }
    }
};
