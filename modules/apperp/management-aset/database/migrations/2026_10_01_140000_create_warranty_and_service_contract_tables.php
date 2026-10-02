<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garansi aset dan kontrak servis vendor: padanan *Vendor warranty* pada aset di Dynamics 365 F&O
 * Asset Management dan *Maintenance Vendor No.* serta *Warranty Date* pada kartu aset Business Central.
 *
 * Keduanya hanya informasi. Tidak ada yang memblokir work order dan tidak ada yang diposting.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Satu garansi untuk satu aset. Garansi tambahan (perpanjangan, garansi suku cadang) adalah baris
        // baru, bukan penimpaan baris lama, supaya riwayat cakupan aset tetap terbaca.
        Schema::create('aset_tr_garansi_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->ulid('aset_id');
            // Vendor milik Core (K-06); kosong bila penjaminnya bukan vendor yang tercatat.
            $table->ulid('vendor_id')->nullable();
            // `penuh` atau `sebagian`, padanan *full* dan *partial coverage* pada warranty agreement F&O.
            $table->string('jenis_garansi', 20);
            // Nomor kartu atau sertifikat garansi dari penjamin.
            $table->string('nomor_referensi', 80)->nullable();
            $table->date('berlaku_mulai');
            $table->date('berlaku_sampai');
            $table->text('catatan')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'aset_id'], 'aset_tr_garansi_aset_ix');
            $table->index(['tenant_id', 'berlaku_sampai'], 'aset_tr_garansi_berakhir_ix');
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_garansi_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE aset_tr_garansi_aset ADD CONSTRAINT aset_tr_garansi_periode '.
            'CHECK (berlaku_sampai >= berlaku_mulai)'
        );

        // Kontrak servis dengan vendor pemeliharaan: satu kontrak, satu vendor, satu periode, banyak aset.
        // Milik satu entitas legal karena vendornya vendor entitas legal itu.
        Schema::create('aset_tr_kontrak_servis', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            // Nomor kontrak dari vendor, bukan nomor yang diterbitkan sistem.
            $table->string('nomor_kontrak', 80);
            $table->ulid('vendor_id');
            $table->date('berlaku_mulai');
            $table->date('berlaku_sampai');
            // Pekerjaan yang ditanggung vendor, misalnya kunjungan berkala dan suku cadang.
            $table->text('cakupan')->nullable();
            $table->decimal('nilai_kontrak', 18, 2)->nullable();
            $table->text('keterangan')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'berlaku_sampai'], 'aset_tr_kontrak_servis_berakhir_ix');
        });
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_kontrak_servis_kode_unique '.
            'ON aset_tr_kontrak_servis (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'ALTER TABLE aset_tr_kontrak_servis ADD CONSTRAINT aset_tr_kontrak_servis_periode '.
            'CHECK (berlaku_sampai >= berlaku_mulai)'
        );

        // Aset yang ditanggung satu kontrak.
        Schema::create('aset_tr_kontrak_servis_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('kontrak_servis_id');
            $table->unsignedInteger('line_number');
            $table->ulid('aset_id');
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            // Nomor baris tidak dipakai ulang, juga sesudah barisnya diarsipkan.
            $table->unique(['tenant_id', 'kontrak_servis_id', 'line_number'], 'aset_tr_kontrak_servis_line_uq');
            $table->index(['tenant_id', 'aset_id'], 'aset_tr_kontrak_servis_line_aset_ix');
            $table->foreign(['tenant_id', 'kontrak_servis_id'], 'aset_tr_kontrak_servis_line_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_kontrak_servis')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_kontrak_servis_line_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
        });
        // Satu aset paling banyak sekali pada satu kontrak. Parsial: aset yang dikeluarkan lalu
        // dimasukkan lagi mendapat baris baru.
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_kontrak_servis_line_aset_unique '.
            'ON aset_tr_kontrak_servis_aset (tenant_id, kontrak_servis_id, aset_id) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('aset_tr_garansi_aset');
        AuditColumns::attach('aset_tr_kontrak_servis');
        AuditColumns::attach('aset_tr_kontrak_servis_aset');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_tr_kontrak_servis_aset');
        Schema::dropIfExists('aset_tr_kontrak_servis');
        Schema::dropIfExists('aset_tr_garansi_aset');
    }
};
