<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo penurunan nilai dan kenaikan nilai per buku aset, padanan FlowField `Write-Down` dan
 * `Appreciation` pada `FA Depreciation Book` Business Central.
 *
 * Keduanya bagian dari nilai buku (`Part of Book Value` bawaan BC): `net_book_value` = harga perolehan −
 * akumulasi penyusutan − penurunan nilai + kenaikan nilai. Dibaca pelepasan untuk membalik saldonya
 * ("Write-Down Acc. on Disposal"), dan oleh penghitung penyusutan untuk membagi nilai buku baru ke sisa
 * masa manfaat. Nol untuk seluruh buku yang sudah ada, karena dokumen penyesuaian nilai baru lahir di
 * rilis ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_buku_aset', function (Blueprint $table): void {
            $table->decimal('write_down_amount', 18, 2)->default(0);
            $table->decimal('appreciation_amount', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('aset_tr_buku_aset', function (Blueprint $table): void {
            $table->dropColumn(['write_down_amount', 'appreciation_amount']);
        });
    }
};
