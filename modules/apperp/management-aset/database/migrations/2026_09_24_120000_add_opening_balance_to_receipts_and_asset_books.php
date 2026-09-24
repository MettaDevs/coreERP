<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo awal aset lama saat cutover (feed posting finance, TODO 10.1, 10.2; K-13, K-27, K-28).
 *
 * **Baris penerimaan saldo awal** membawa akumulasi penyusutan per unit dan jumlah periode yang
 * sudah disusutkan sampai cutover. Keduanya angka buku yang di-post ke finance, sekaligus bawaan
 * buku lain. Buku yang angkanya berbeda — lazimnya buku fiskal — dicatat di `saldo_awal_buku`,
 * karena akumulasi aset tetap memang milik tiap buku, seperti transaksi aset per buku di F&O dan
 * BC (K-28).
 *
 * **Buku aset** menyimpan akumulasi saldo awalnya terpisah dari akumulasi berjalan. Tanpa itu
 * `accumulated_depreciation = opening_accumulated_depreciation + jumlah periode final` tidak dapat
 * diperiksa lagi. `elapsed_periods_offset` ditambahkan ke hitungan periode berjalan saat penyusutan
 * diusulkan, sehingga periode pertama sesudah cutover adalah periode ke-(offset + 1) — padanan
 * "Depreciation periods remaining" pada buku aset F&O.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_penerimaan_aset_details', function (Blueprint $table): void {
            $table->decimal('akumulasi_per_unit', 18, 2)->default(0);
            $table->unsignedInteger('periode_berjalan')->default(0);
            $table->json('saldo_awal_buku')->nullable();
        });

        Schema::table('aset_tr_buku_aset', function (Blueprint $table): void {
            $table->decimal('opening_accumulated_depreciation', 18, 2)->default(0);
            $table->unsignedInteger('elapsed_periods_offset')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('aset_tr_buku_aset', function (Blueprint $table): void {
            $table->dropColumn(['opening_accumulated_depreciation', 'elapsed_periods_offset']);
        });

        Schema::table('aset_tr_penerimaan_aset_details', function (Blueprint $table): void {
            $table->dropColumn(['akumulasi_per_unit', 'periode_berjalan', 'saldo_awal_buku']);
        });
    }
};
