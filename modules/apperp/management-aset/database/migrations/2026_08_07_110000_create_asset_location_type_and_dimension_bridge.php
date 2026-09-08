<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Padanan "Functional location type" di Dynamics 365 F&O: datar, sementara
        // kedalaman pohon lokasi ditentukan data lewat m_lokasi_aset.parent_id.
        Schema::create('m_tipe_lokasi_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'kode']);
            $table->unique(['tenant_id', 'creation_key']);
        });

        Schema::table('m_lokasi_aset', function (Blueprint $table): void {
            $table->ulid('tipe_lokasi_id')->nullable();
            // Jembatan ke unit organisasi Core; padanan toggle "Update asset dimension"
            // di F&O. Aset yang ditempatkan di lokasi ini mewarisi unit ini sebagai
            // dimensi keuangannya, sehingga pohon lokasi fisik tidak perlu mengikuti
            // bentuk struktur organisasi hanya demi kebutuhan akuntansi.
            //
            // Tanpa foreign key: unit organisasi dimiliki Core, dan app ini tidak
            // pernah menyentuh database Core.
            $table->ulid('org_unit_id')->nullable()->index();
            $table->index(['tenant_id', 'tipe_lokasi_id']);
            $table->foreign(['tenant_id', 'tipe_lokasi_id'])
                ->references(['tenant_id', 'id'])
                ->on('m_tipe_lokasi_aset')
                ->restrictOnDelete();
        });

        Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
            // Disalin dari lokasi saat aset diterima atau dimutasi, lalu disimpan pada
            // aset. Nilai yang tersimpan adalah keputusan pada saat itu; mengubah
            // pemetaan lokasi kelak tidak menulis ulang dimensi aset yang sudah berjalan.
            $table->ulid('financial_dimension_org_unit_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tr_penerimaan_aset', function (Blueprint $table): void {
            $table->dropIndex('tr_penerimaan_aset_financial_dimension_org_unit_id_index');
            $table->dropColumn('financial_dimension_org_unit_id');
        });

        Schema::table('m_lokasi_aset', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'tipe_lokasi_id']);
            $table->dropIndex(['tenant_id', 'tipe_lokasi_id']);
            $table->dropIndex('m_lokasi_aset_org_unit_id_index');
            $table->dropColumn(['tipe_lokasi_id', 'org_unit_id']);
        });

        Schema::dropIfExists('m_tipe_lokasi_aset');
    }
};
