<?php

use App\Platform\Modules\Contracts\AuditColumns;
use App\Platform\Modules\Contracts\ChangeLogDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asuransi aset tetap: padanan folder *Insurance* di Business Central (tabel `Insurance Type`,
 * `Insurance`, dan `Ins. Coverage Ledger Entry`).
 *
 * Tidak ada yang diposting ke buku besar. BC pun hanya mencatat pertanggungan di ledger-nya sendiri;
 * premi dibayar lewat tagihan vendor biasa di aplikasi finance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // *Insurance Type* BC: golongan polis, misalnya kebakaran atau kendaraan.
        Schema::create('aset_m_jenis_asuransi', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
        });
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_jenis_asuransi_kode_unique '.
            'ON aset_m_jenis_asuransi (tenant_id, kode) WHERE deleted_at IS NULL'
        );

        // *Insurance* BC: satu polis. Milik satu entitas legal, karena penanggungnya vendor entitas
        // legal itu dan aset yang ditanggungnya juga milik satu entitas legal.
        Schema::create('aset_m_polis_asuransi', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            $table->string('nama', 150);
            // Nomor polis dari penanggung (*Policy No.*), bukan nomor yang diterbitkan sistem.
            $table->string('nomor_polis', 60);
            $table->ulid('jenis_asuransi_id')->nullable();
            // Vendor milik Core (K-06). Disimpan idnya saja; nama dibaca ulang lewat VendorDirectory.
            $table->ulid('vendor_id')->nullable();
            $table->date('berlaku_mulai');
            $table->date('berlaku_sampai')->nullable();
            $table->decimal('premi_tahunan', 18, 2)->default(0);
            // *Policy Coverage*: plafon polis. Pembandingnya jumlah pertanggungan aset (*Total Value Insured*).
            $table->decimal('nilai_pertanggungan', 18, 2)->default(0);
            // *Blocked*: polis yang diblokir tidak dapat diberi pertanggungan baru.
            $table->boolean('diblokir')->default(false);
            $table->text('keterangan')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->foreign(['tenant_id', 'jenis_asuransi_id'], 'aset_m_polis_asuransi_jenis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_jenis_asuransi')->restrictOnDelete();
        });
        // Nomor terbit per entitas legal, seperti dokumen transaksi lain modul ini.
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_polis_asuransi_kode_unique '.
            'ON aset_m_polis_asuransi (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL'
        );
        DB::statement(
            'ALTER TABLE aset_m_polis_asuransi ADD CONSTRAINT aset_m_polis_asuransi_periode '.
            'CHECK (berlaku_sampai IS NULL OR berlaku_sampai >= berlaku_mulai)'
        );

        // Pertanggungan satu aset pada satu polis untuk satu periode; padanan entri *Ins. Coverage
        // Ledger Entry* BC, dengan periode menggantikan entri positif dan negatif.
        //
        // Barisnya riwayat, bukan setelan yang ditimpa. Nilai yang berubah mengakhiri baris lama sehari
        // sebelum nilai baru berlaku dan membuat baris baru, sehingga pertanggungan pada tanggal mana pun
        // tetap dapat dijawab. Nilai dan tanggal mulai tidak pernah disunting.
        Schema::create('aset_tr_pertanggungan_asuransi', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->ulid('polis_asuransi_id');
            $table->ulid('aset_id');
            $table->decimal('nilai_pertanggungan', 18, 2);
            $table->date('berlaku_mulai');
            // Kosong berarti mengikuti masa berlaku polisnya.
            $table->date('berlaku_sampai')->nullable();
            $table->text('keterangan')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'aset_id'], 'aset_tr_pertanggungan_aset_ix');
            $table->index(['tenant_id', 'polis_asuransi_id'], 'aset_tr_pertanggungan_polis_ix');
            $table->foreign(['tenant_id', 'polis_asuransi_id'], 'aset_tr_pertanggungan_polis_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_polis_asuransi')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_pertanggungan_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE aset_tr_pertanggungan_asuransi ADD CONSTRAINT aset_tr_pertanggungan_periode '.
            'CHECK (berlaku_sampai IS NULL OR berlaku_sampai >= berlaku_mulai)'
        );
        DB::statement(
            'ALTER TABLE aset_tr_pertanggungan_asuransi ADD CONSTRAINT aset_tr_pertanggungan_nilai '.
            'CHECK (nilai_pertanggungan > 0)'
        );

        AuditColumns::attach('aset_m_jenis_asuransi');
        AuditColumns::attach('aset_m_polis_asuransi');
        AuditColumns::attach('aset_tr_pertanggungan_asuransi');

        // Riwayat perubahan polis: siapa mengubah plafon, premi, masa berlaku, atau memblokirnya.
        // Migration, bukan seeder, supaya bawaan ini sampai ke tenant yang sudah memasang module.
        ChangeLogDefaults::register('aset_m_polis_asuransi', 'Polis asuransi', [
            'nomor_polis' => 'Nomor polis',
            'berlaku_mulai' => 'Berlaku mulai',
            'berlaku_sampai' => 'Berlaku sampai',
            'premi_tahunan' => 'Premi tahunan',
            'nilai_pertanggungan' => 'Nilai pertanggungan polis',
            'diblokir' => 'Diblokir',
            'deleted_at' => 'Diarsipkan',
        ]);
    }

    public function down(): void
    {
        ChangeLogDefaults::disable('aset_m_polis_asuransi');
        Schema::dropIfExists('aset_tr_pertanggungan_asuransi');
        Schema::dropIfExists('aset_m_polis_asuransi');
        Schema::dropIfExists('aset_m_jenis_asuransi');
    }
};
