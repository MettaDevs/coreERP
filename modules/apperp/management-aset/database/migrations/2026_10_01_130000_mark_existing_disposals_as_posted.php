<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Penjualan dan pemusnahan yang sudah ada ditandai `posted`.
 *
 * Sampai rilis ini dokumen pelepasan melepas aset dan menutup bukunya saat disimpan, padahal statusnya
 * tetap `draft`. Sejak rilis ini draf benar-benar draf, dan pelepasan terjadi saat diposting. Dokumen lama
 * sudah melepas asetnya, jadi statusnya disamakan dengan kenyataan; tanpa ini mereka muncul sebagai draf
 * yang dapat diposting dua kali, dan hilang dari laporan penjualan dan pemusnahan yang kini hanya membaca
 * dokumen terposting. Jurnal pelepasannya tidak diterbitkan mundur: pelepasan itu terjadi sebelum jalur
 * jurnal ini ada, dan dicatat manual di aplikasi finance seperti transaksi sebelum cutover.
 *
 * Migration, bukan seeder, supaya sampai ke tenant yang sudah memasang module.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('aset_tr_dokumen_siklus_aset')
            ->whereIn('jenis_dokumen', ['penjualan-aset', 'pemusnahan-aset'])
            ->where('status', 'draft')
            ->update(['status' => 'posted', 'updated_at' => now()]);
    }

    /** Tidak dibalik: status `draft` untuk dokumen yang sudah melepas asetnya adalah keadaan yang salah. */
    public function down(): void {}
};
