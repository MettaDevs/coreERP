<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rencana pemeliharaan preventif dan jadwal yang dihitung darinya: padanan *Maintenance plans* dan
 * *Maintenance schedule* di Dynamics 365 F&O Asset Management.
 *
 * Rencana adalah master: header (tanggal mulai dan toleransi), baris (setiap N hari/minggu/bulan/tahun
 * atau setiap N satuan counter, dikaitkan ke jenis pekerjaan), dan objek (aset tertentu atau seluruh
 * aset satu jenis). Jadwal adalah hasil hitungan: satu baris usulan per jatuh tempo per aset, yang
 * diubah pengguna menjadi work order atau diabaikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_m_rencana_pemeliharaan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            // *Plan date* F&O: titik hitung baris berbasis waktu bila objeknya tidak menyebut
            // tanggal mulai sendiri.
            $table->date('tanggal_mulai');
            // *Tolerance days before/after* F&O. Usulan tidak dibuat bila aset sudah punya work order
            // jenis pekerjaan yang sama dalam rentang ini di sekitar tanggal jatuh tempo.
            $table->unsignedSmallInteger('toleransi_hari_sebelum')->default(0);
            $table->unsignedSmallInteger('toleransi_hari_sesudah')->default(0);
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
        });
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_rencana_pemeliharaan_kode_unique '.
            'ON aset_m_rencana_pemeliharaan (tenant_id, kode) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_m_rencana_pemeliharaan_baris', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('rencana_pemeliharaan_id');
            $table->unsignedInteger('line_number');
            // `tanggal_mulai`, `work_order_terakhir`, atau `nilai_counter`. Lihat
            // `MaintenancePlanBasis`.
            $table->string('dasar', 30);
            $table->unsignedInteger('interval')->nullable();
            // `hari`, `minggu`, `bulan`, atau `tahun`; hanya untuk baris berbasis waktu.
            $table->string('satuan_interval', 10)->nullable();
            $table->ulid('jenis_counter_id')->nullable();
            $table->decimal('interval_counter', 18, 2)->nullable();
            // Usulan muncul saat total counter sudah mencapai batas dikurangi angka ini.
            $table->decimal('toleransi_counter', 18, 2)->default(0);
            $table->ulid('maintenance_job_type_id');
            $table->ulid('variant_id')->nullable();
            $table->ulid('trade_id')->nullable();
            $table->ulid('tipe_work_order_id');
            $table->ulid('tingkat_layanan_id')->nullable();
            // *Finish within days* F&O: diharapkan selesai = jatuh tempo + angka ini.
            $table->unsignedSmallInteger('selesai_dalam_hari')->nullable();
            // *Work order description*, disalin ke keterangan work order.
            $table->text('deskripsi')->nullable();
            $table->boolean('aktif')->default(true);
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'rencana_pemeliharaan_id'], 'aset_m_rencana_baris_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_rencana_pemeliharaan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'jenis_counter_id'], 'aset_m_rencana_baris_counter_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_counter')->restrictOnDelete();
            $table->foreign(['tenant_id', 'maintenance_job_type_id'], 'aset_m_rencana_baris_job_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_maintenance_job_type')->restrictOnDelete();
            $table->foreign(['tenant_id', 'variant_id'], 'aset_m_rencana_baris_variant_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_maintenance_job_type_variant')->restrictOnDelete();
            $table->foreign(['tenant_id', 'trade_id'], 'aset_m_rencana_baris_trade_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_trade')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tipe_work_order_id'], 'aset_m_rencana_baris_tipe_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_tipe_work_order')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tingkat_layanan_id'], 'aset_m_rencana_baris_layanan_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_tingkat_layanan')->restrictOnDelete();
        });
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_rencana_baris_line_unique '.
            'ON aset_m_rencana_pemeliharaan_baris (tenant_id, rencana_pemeliharaan_id, line_number) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_m_rencana_pemeliharaan_objek', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('rencana_pemeliharaan_id');
            // Tepat satu dari keduanya terisi: satu aset, atau seluruh aset satu jenis.
            $table->ulid('aset_id')->nullable();
            $table->ulid('jenis_aset_id')->nullable();
            // *Start date* pada FastTab *Asset maintenance plans* F&O; kosong berarti tanggal mulai
            // rencana.
            $table->date('tanggal_mulai')->nullable();
            $table->boolean('aktif')->default(true);
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'rencana_pemeliharaan_id'], 'aset_m_rencana_objek_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_rencana_pemeliharaan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_m_rencana_objek_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'jenis_aset_id'], 'aset_m_rencana_objek_jenis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_aset')->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE aset_m_rencana_pemeliharaan_objek ADD CONSTRAINT aset_m_rencana_objek_satu_sasaran '.
            'CHECK ((aset_id IS NULL) <> (jenis_aset_id IS NULL))'
        );
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_rencana_objek_aset_unique '.
            'ON aset_m_rencana_pemeliharaan_objek (tenant_id, rencana_pemeliharaan_id, aset_id) '.
            'WHERE aset_id IS NOT NULL AND deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_rencana_objek_jenis_unique '.
            'ON aset_m_rencana_pemeliharaan_objek (tenant_id, rencana_pemeliharaan_id, jenis_aset_id) '.
            'WHERE jenis_aset_id IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::create('aset_tr_jadwal_pemeliharaan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('rencana_pemeliharaan_id');
            $table->ulid('rencana_baris_id');
            $table->ulid('aset_id');
            // Pemilik usulan menurut kebijakan organisasi: entitas legal dan unit penanggung jawab
            // aset saat usulan dihitung.
            $table->ulid('legal_entity_id');
            $table->ulid('responsible_org_unit_id')->nullable();
            $table->date('jatuh_tempo');
            // Batas total counter yang dicapai; kosong untuk baris berbasis waktu.
            $table->decimal('nilai_jatuh_tempo', 18, 2)->nullable();
            // Total counter saat usulan dihitung.
            $table->decimal('nilai_counter', 18, 2)->nullable();
            // `usulan`, `work_order_dibuat`, atau `diabaikan`. Lihat `MaintenanceScheduleStatus`.
            $table->string('status', 30)->default('usulan');
            $table->ulid('pemeliharaan_aset_id')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'aset_tr_jadwal_scope_ix');
            $table->index(['tenant_id', 'status', 'jatuh_tempo'], 'aset_tr_jadwal_tempo_ix');
            $table->foreign(['tenant_id', 'rencana_pemeliharaan_id'], 'aset_tr_jadwal_rencana_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_rencana_pemeliharaan')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rencana_baris_id'], 'aset_tr_jadwal_baris_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_rencana_pemeliharaan_baris')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_jadwal_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pemeliharaan_aset_id'], 'aset_tr_jadwal_wo_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_pemeliharaan_aset')->restrictOnDelete();
        });
        // Satu jatuh tempo, satu usulan — inilah yang membuat perhitungan ulang idempoten. Usulan yang
        // diabaikan tetap memegang kuncinya, jadi ia tidak lahir lagi pada perhitungan berikutnya
        // (F&O: *discarded lines never come back*). Baris waktu dikunci tanggalnya, baris counter
        // dikunci batas totalnya.
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_jadwal_waktu_unique '.
            'ON aset_tr_jadwal_pemeliharaan (tenant_id, rencana_baris_id, aset_id, jatuh_tempo) '.
            'WHERE nilai_jatuh_tempo IS NULL AND deleted_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_jadwal_counter_unique '.
            'ON aset_tr_jadwal_pemeliharaan (tenant_id, rencana_baris_id, aset_id, nilai_jatuh_tempo) '.
            'WHERE nilai_jatuh_tempo IS NOT NULL AND deleted_at IS NULL'
        );

        AuditColumns::attach('aset_m_rencana_pemeliharaan');
        AuditColumns::attach('aset_m_rencana_pemeliharaan_baris');
        AuditColumns::attach('aset_m_rencana_pemeliharaan_objek');
        AuditColumns::attach('aset_tr_jadwal_pemeliharaan');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_tr_jadwal_pemeliharaan');
        Schema::dropIfExists('aset_m_rencana_pemeliharaan_objek');
        Schema::dropIfExists('aset_m_rencana_pemeliharaan_baris');
        Schema::dropIfExists('aset_m_rencana_pemeliharaan');
    }
};
