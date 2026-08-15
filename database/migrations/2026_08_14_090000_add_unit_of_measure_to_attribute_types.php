<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satuan tipe atribut naik dari teks bebas menjadi rujukan ke satuan milik Core.
 *
 * Kolom `satuan` tetap ada dan tetap menjadi yang dibaca layar: ia sekarang salinan kode
 * satuan yang sudah tervalidasi, bukan ketikan pengguna. Menyimpan keduanya disengaja —
 * `satuan_id` yang menentukan, `satuan` yang menampilkan — sehingga form penerimaan aset
 * dan definisi atribut tidak perlu menghubungi Core hanya untuk menampilkan "cm".
 *
 * Tidak ada foreign key: satuan dimiliki Core, bukan basis data app ini, jadi rujukannya
 * berupa id buram yang divalidasi lewat kontrak, persis seperti legal entity dan org unit.
 * Baris lama dibiarkan apa adanya; `satuan_id` yang kosong berarti satuannya masih teks
 * warisan yang belum dipetakan ke satuan Core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_tipe_atribut', function (Blueprint $table): void {
            $table->ulid('satuan_id')->nullable()->after('data_type');
        });
    }

    public function down(): void
    {
        Schema::table('m_tipe_atribut', function (Blueprint $table): void {
            $table->dropColumn('satuan_id');
        });
    }
};
