<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan barang: bahan uji engine analitik, supaya test engine tidak bergantung pada module aset.
 *
 * Bentuknya sengaja memuat setiap hal yang dibaca engine dari dataset: kolom kebijakan data (entitas legal
 * dan unit kerja), uang dengan kolom mata uangnya, satu kolom pilihan, rujukan ke master module yang sama,
 * id pengguna, satu kolom data pribadi, dan ketiga jenis kolom waktu (`date`, `timestamp` berisi UTC,
 * `timestamptz`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contoh_a_tr_penjualan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('barang_id');
            $table->ulid('legal_entity_id');
            $table->ulid('org_unit_id');
            $table->string('status', 20)->default('draf');
            $table->decimal('nilai', 18, 2);
            $table->string('currency_code', 3);
            $table->date('tanggal');
            $table->timestamp('dicatat_pada')->nullable();
            $table->timestampTz('dibayar_pada')->nullable();
            $table->unsignedBigInteger('dicatat_oleh_user_id')->nullable();
            $table->string('nama_pembeli', 150)->nullable();
            $table->text('keterangan')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contoh_a_tr_penjualan');
    }
};
