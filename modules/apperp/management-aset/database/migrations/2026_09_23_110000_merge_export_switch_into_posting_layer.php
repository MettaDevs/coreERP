<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Saklar `export_to_backoffice` dilebur ke `posting_layer` (K-15, TODO 8.4.1). Sejak rilis ini
 * hanya `posting_layer` yang dibaca: buku `none` tidak pernah di-post, selain itu di-post.
 *
 * Datanya yang diubah hanya buku pajak, `tax` menjadi `none`. Aplikasi finance pelanggan hanya
 * punya satu lapisan GL, jadi buku fiskal yang di-post akan menjurnal penyusutan aset yang sama
 * untuk kedua kalinya.
 *
 * TODO meminta buku lain yang saklarnya mati "ditinjau satu per satu". Peninjauan itu tidak
 * menemukan keputusan untuk ditiru: sejak 12 Agustus 2026 saklar itu bawaannya mati dan layar
 * memintanya dibiarkan mati sampai kontrak posting ada, jadi `false` di sebuah buku komersial
 * berarti "belum ada bridge", bukan "buku ini jangan di-post". Buku seperti itu dibiarkan
 * memakai lapisannya dan ikut di-post. Belum ada pelanggan yang memakai modul ini (AGENTS.md).
 *
 * Kolom lamanya tidak disentuh: rilis sebelumnya masih membacanya (aturan N-1), dan kolomnya
 * dibuang satu rilis kemudian (TODO 8.4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('aset_m_buku_penyusutan')
            ->where('posting_layer', 'tax')
            ->update(['posting_layer' => 'none', 'updated_at' => now()]);
    }

    /**
     * Tidak dikembalikan: sesudah `up()`, buku yang dulu `tax` tidak lagi dapat dibedakan dari buku
     * yang memang memorandum. Rilis sebelumnya tetap berjalan di atas data ini, karena ekspornya
     * membaca `export_to_backoffice`, bukan lapisannya.
     */
    public function down(): void {}
};
