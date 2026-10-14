<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor PO manual merujuk bukti pembelian; bukan nomor permintaan pengadaan atau PO baru.
 *
 * @kompatibel-mundur kolom opsional; kode lama mengabaikannya dan baris lama tetap null
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_penerimaan_aset', function (Blueprint $table): void {
            $table->string('purchase_order_reference', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aset_tr_penerimaan_aset', function (Blueprint $table): void {
            $table->dropColumn('purchase_order_reference');
        });
    }
};
