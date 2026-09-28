<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom akun kedelapan pada posting group: lawan hibah (keputusan pemilik produk, 24 September 2026).
 *
 * Aset hibah tetap masuk harga perolehan (K-12), tetapi lawannya bukan hutang: tidak ada pemasok
 * yang ditagih. Akunnya dipilih konsultan, lazimnya ekuitas atau pendapatan hibah, jadi ia dipetakan
 * per group seperti akun lain. Kosong berarti posting hibah tertahan di Core (K-18), sementara
 * penerimaannya tetap selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_m_posting_group', function (Blueprint $table): void {
            $table->ulid('grant_offset_account_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aset_m_posting_group', function (Blueprint $table): void {
            $table->dropColumn('grant_offset_account_id');
        });
    }
};
