<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda baris bawaan module.
 *
 * Baris yang lahir dari data awal module harus bisa dibedakan dari baris yang diketik
 * pengguna. Tanpa penanda ini, sebuah pemulihan atau pembersihan tidak punya cara memisahkan
 * keduanya selain menebak dari tanggal, dan menebak berarti suatu saat membuang data
 * pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contoh_a_m_barang', function (Blueprint $table): void {
            $table->boolean('bawaan')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('contoh_a_m_barang', function (Blueprint $table): void {
            $table->dropColumn('bawaan');
        });
    }
};
