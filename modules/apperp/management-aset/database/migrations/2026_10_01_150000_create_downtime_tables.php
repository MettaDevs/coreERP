<?php

use App\Platform\Modules\Contracts\AuditColumns;
use App\Platform\Modules\Contracts\ChangeLogDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pencatatan downtime aset: padanan *Maintenance downtime* dan *Maintenance downtime reason codes* di
 * Dynamics 365 F&O Asset Management. Catatan ini mencatat waktu aset benar-benar tidak dapat dipakai,
 * dan menjadi dasar availability serta jumlah henti pada KPI pemeliharaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // *Maintenance downtime reason code* F&O, dengan *KPI include*: henti terencana biasanya tidak
        // dihitung sebagai berkurangnya ketersediaan.
        Schema::create('aset_m_alasan_downtime', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->boolean('masuk_kpi')->default(true);
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
        });
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_alasan_downtime_kode_unique '.
            'ON aset_m_alasan_downtime (tenant_id, kode) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_tr_downtime_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->ulid('aset_id');
            // Waktu dalam UTC, seperti waktu work order.
            $table->dateTime('mulai');
            // Kosong selama aset masih berhenti.
            $table->dateTime('selesai')->nullable();
            // Kosong tetap dihitung pada KPI, seperti registrasi tanpa reason code di F&O.
            $table->ulid('alasan_downtime_id')->nullable();
            $table->ulid('pemeliharaan_aset_id')->nullable();
            $table->ulid('permintaan_pemeliharaan_id')->nullable();
            // `manual` dicatat pengguna; `work_order` dibuka dan ditutup perpindahan status work order.
            $table->string('sumber', 20)->default('manual');
            $table->text('keterangan')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'aset_id', 'mulai'], 'aset_tr_downtime_aset_mulai_ix');
            $table->index(['tenant_id', 'pemeliharaan_aset_id'], 'aset_tr_downtime_wo_ix');
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_downtime_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'alasan_downtime_id'], 'aset_tr_downtime_alasan_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_alasan_downtime')->restrictOnDelete();
            $table->foreign(['tenant_id', 'pemeliharaan_aset_id'], 'aset_tr_downtime_wo_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_pemeliharaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'permintaan_pemeliharaan_id'], 'aset_tr_downtime_permintaan_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_permintaan_pemeliharaan')->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE aset_tr_downtime_aset ADD CONSTRAINT aset_tr_downtime_periode '.
            'CHECK (selesai IS NULL OR selesai > mulai)'
        );
        // Satu aset paling banyak satu downtime yang masih terbuka: aset yang sudah berhenti tidak dapat
        // berhenti lagi. Downtime yang tumpang tindih ditolak controller; indeks ini menjaga balapan
        // dua pencatatan yang sama-sama terbuka.
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_downtime_terbuka_unique '.
            'ON aset_tr_downtime_aset (tenant_id, aset_id) WHERE deleted_at IS NULL AND selesai IS NULL'
        );

        AuditColumns::attach('aset_m_alasan_downtime');
        AuditColumns::attach('aset_tr_downtime_aset');

        // Waktu downtime menggerakkan availability; koreksinya perlu jejak.
        ChangeLogDefaults::register('aset_tr_downtime_aset', 'Downtime aset', [
            'mulai' => 'Mulai',
            'selesai' => 'Selesai',
            'deleted_at' => 'Diarsipkan',
        ]);
    }

    public function down(): void
    {
        ChangeLogDefaults::disable('aset_tr_downtime_aset');
        Schema::dropIfExists('aset_tr_downtime_aset');
        Schema::dropIfExists('aset_m_alasan_downtime');
    }
};
