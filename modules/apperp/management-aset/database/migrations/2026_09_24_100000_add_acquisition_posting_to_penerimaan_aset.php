<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penerimaan aset membawa yang dibutuhkan jurnal perolehan (feed posting finance, TODO 9.1).
 *
 * **Vendor, faktur, dan cara perolehan di kepala dokumen.** Satu penerimaan adalah satu kedatangan
 * dari satu pemasok, dan jurnalnya satu posting per penerimaan. Vendor milik Core (K-06) dan
 * disimpan sebagai id opaque tanpa foreign key, sama seperti unit kerja dan orang. Tanggal faktur
 * menjadi `document_date` posting bila diisi (TODO 9.4.3); tanpa kolom itu posting hanya mengenal
 * tanggal penerimaan.
 *
 * **PPN per unit di baris, seperti nilainya** (K-11, TODO 9.1.2). PPN baris = bulat(PPN per unit ×
 * jumlah), dibulatkan sekali ke presisi nilai mata uang, sama seperti nilai baris (K-20).
 *
 * @kompatibel-mundur nilai_per_unit dilebarkan dari decimal(18,2) ke decimal(24,6) supaya harga satuan dapat memakai presisi harga satuan mata uangnya (K-20); kode lama tetap menulis dan membaca nilai dua desimal yang muat di kolom yang lebih lebar
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_tr_penerimaan_aset', function (Blueprint $table): void {
            $table->string('cara_perolehan', 20)->default('pembelian');
            $table->ulid('vendor_id')->nullable();
            $table->string('vendor_invoice_reference', 80)->nullable();
            $table->date('vendor_invoice_date')->nullable();
        });

        Schema::table('aset_tr_penerimaan_aset_details', function (Blueprint $table): void {
            $table->decimal('nilai_per_unit', 24, 6)->change();
            $table->decimal('ppn_per_unit', 24, 6)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('aset_tr_penerimaan_aset_details', function (Blueprint $table): void {
            $table->dropColumn('ppn_per_unit');
            $table->decimal('nilai_per_unit', 18, 2)->change();
        });

        Schema::table('aset_tr_penerimaan_aset', function (Blueprint $table): void {
            $table->dropColumn(['cara_perolehan', 'vendor_id', 'vendor_invoice_reference', 'vendor_invoice_date']);
        });
    }
};
