<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan pemeliharaan: padanan *Maintenance requests* di Dynamics 365 F&O Asset Management.
 *
 * Unit melaporkan kerusakan atau kebutuhan perbaikan atas aset atau lokasi. Permintaan bukan work
 * order; ia diajukan, diterima atau ditolak, lalu yang diterima dibuatkan satu work order.
 */
return new class extends Migration
{
    public function up(): void
    {
        // *Maintenance request types* F&O. Tipe work order bawaan diwarisi work order yang dibuat dari
        // permintaan jenis ini.
        Schema::create('aset_m_jenis_permintaan_pemeliharaan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->ulid('tipe_work_order_id')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->foreign(['tenant_id', 'tipe_work_order_id'], 'aset_m_jenis_permintaan_tipe_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_tipe_work_order')->restrictOnDelete();
        });
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_jenis_permintaan_kode_unique '.
            'ON aset_m_jenis_permintaan_pemeliharaan (tenant_id, kode) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_tr_permintaan_pemeliharaan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            // Unit yang melapor, sekaligus pemilik permintaan menurut kebijakan organisasi.
            $table->ulid('responsible_org_unit_id')->index();
            $table->ulid('jenis_permintaan_id');
            // Aset boleh kosong bila yang dilaporkan sebuah lokasi; salah satunya wajib.
            $table->ulid('aset_id')->nullable();
            $table->ulid('lokasi_aset_id')->nullable();
            $table->text('deskripsi');
            $table->ulid('tingkat_layanan_id')->nullable();
            $table->ulid('sebab_kerusakan_id')->nullable();
            // `draft`, `diajukan`, `diterima`, `ditolak`, atau `work_order_dibuat`. Lihat
            // `MaintenanceRequestStatus`.
            $table->string('status', 30)->default('draft');
            $table->timestamp('diajukan_pada')->nullable();
            $table->timestamp('diputuskan_pada')->nullable();
            // ID pengguna Core bersifat opaque dan tidak pernah menjadi foreign key lintas modul.
            $table->string('diputuskan_oleh_user_id', 64)->nullable();
            $table->text('alasan_penolakan')->nullable();
            // Satu permintaan, paling banyak satu work order.
            $table->ulid('pemeliharaan_aset_id')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'aset_tr_permintaan_pml_scope_ix');
            $table->index(['tenant_id', 'aset_id'], 'aset_tr_permintaan_pml_aset_ix');
            $table->foreign(['tenant_id', 'jenis_permintaan_id'], 'aset_tr_permintaan_pml_jenis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_permintaan_pemeliharaan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_permintaan_pml_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'lokasi_aset_id'], 'aset_tr_permintaan_pml_lokasi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_lokasi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tingkat_layanan_id'], 'aset_tr_permintaan_pml_layanan_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_tingkat_layanan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sebab_kerusakan_id'], 'aset_tr_permintaan_pml_sebab_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_sebab_kerusakan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pemeliharaan_aset_id'], 'aset_tr_permintaan_pml_wo_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_pemeliharaan_aset')->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE aset_tr_permintaan_pemeliharaan ADD CONSTRAINT aset_tr_permintaan_pml_sasaran '.
            'CHECK (aset_id IS NOT NULL OR lokasi_aset_id IS NOT NULL)'
        );
        // Nomor terbit per entitas legal, seperti dokumen transaksi lain modul ini.
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_permintaan_pml_kode_unique '.
            'ON aset_tr_permintaan_pemeliharaan (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('aset_m_jenis_permintaan_pemeliharaan');
        AuditColumns::attach('aset_tr_permintaan_pemeliharaan');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_tr_permintaan_pemeliharaan');
        Schema::dropIfExists('aset_m_jenis_permintaan_pemeliharaan');
    }
};
