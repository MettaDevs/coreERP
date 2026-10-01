<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Counter aset: padanan *Counters* di Dynamics 365 F&O Asset Management.
 *
 * Tiga tabel. Jenis counter adalah master (misalnya jam operasi atau jumlah tindakan) dengan satuan
 * dari daftar satuan Core. Relasi jenis aset ke jenis counter membatasi counter yang boleh dibaca pada
 * aset sejenis, seperti FastTab *Asset types* pada counter F&O; counter tanpa relasi berlaku untuk
 * semua jenis aset, sama seperti jenis pekerjaan maintenance. Pembacaan counter mencatat angka meter
 * satu aset pada satu waktu beserta total kumulatifnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_m_jenis_counter', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            // Id satuan milik Core, opaque dan tanpa foreign key lintas modul. `satuan` adalah salinan
            // kodenya untuk tampilan, sama seperti pada tipe atribut.
            $table->ulid('satuan_id');
            $table->string('satuan', 40)->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
        });
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_jenis_counter_kode_unique '.
            'ON aset_m_jenis_counter (tenant_id, kode) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_m_jenis_aset_counter', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('jenis_aset_id');
            $table->ulid('jenis_counter_id');
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'jenis_counter_id'], 'aset_m_jenis_aset_counter_counter_ix');
            $table->foreign(['tenant_id', 'jenis_aset_id'], 'aset_m_jenis_aset_counter_jenis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'jenis_counter_id'], 'aset_m_jenis_aset_counter_counter_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_counter')->restrictOnDelete();
        });
        // Relasi yang dilepas diarsipkan, dan pasangan yang sama boleh dipasang lagi sesudahnya.
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_jenis_aset_counter_unique '.
            'ON aset_m_jenis_aset_counter (tenant_id, jenis_aset_id, jenis_counter_id) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_tr_pembacaan_counter', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->ulid('aset_id');
            $table->ulid('jenis_counter_id');
            // Tanggal dan jam baca, padanan *Registered* di F&O. Dua pembacaan boleh berwaktu sama:
            // begitulah F&O mencatat penggantian meter (bacaan akhir meter lama, lalu meter baru).
            $table->dateTime('dibaca_pada');
            // Angka yang tertera di meter.
            $table->decimal('nilai', 18, 2);
            // Total pemakaian sejak counter pertama dibaca, melewati setiap penggantian meter
            // (*Totals* di F&O). Dihitung saat disimpan dan tidak pernah dikirim klien.
            $table->decimal('nilai_total', 18, 2);
            // Meter diganti atau direset. Angka pada baris ini adalah angka awal meter baru, jadi
            // baris ini tidak menambah total dan boleh lebih kecil dari bacaan sebelumnya.
            $table->boolean('reset')->default(false);
            $table->text('keterangan')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'aset_id', 'jenis_counter_id', 'dibaca_pada'], 'aset_tr_pembacaan_counter_urut_ix');
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_pembacaan_counter_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'jenis_counter_id'], 'aset_tr_pembacaan_counter_jenis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_counter')->restrictOnDelete();
        });

        AuditColumns::attach('aset_m_jenis_counter');
        AuditColumns::attach('aset_m_jenis_aset_counter');
        AuditColumns::attach('aset_tr_pembacaan_counter');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_tr_pembacaan_counter');
        Schema::dropIfExists('aset_m_jenis_aset_counter');
        Schema::dropIfExists('aset_m_jenis_counter');
    }
};
