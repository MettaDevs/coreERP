<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen monitoring aset: pemeriksaan fisik (stock opname) aset tetap di satu lokasi.
 *
 * Dokumen ini hanya mencatat temuan. Menyelesaikannya tidak pernah mengubah register aset;
 * perubahan yang sungguhan tetap lewat mutasi atau dekomisioning. Karena itu tabel ini tidak
 * menunjuk dan tidak ditunjuk riwayat penempatan, berbeda dari mutasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_tr_monitoring_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            // "Unit organisasi" pada layar, opsional. Kosong berarti pemeriksaan satu lokasi untuk
            // semua unit; dokumen seperti itu hanya terlihat oleh pengguna yang menjangkau seluruh
            // unit kerja, karena kebijakan organisasi tidak punya unit untuk dicocokkan.
            $table->ulid('responsible_org_unit_id')->nullable()->index();
            // ID pengguna Core bersifat opaque dan tidak pernah menjadi foreign key lintas modul.
            $table->string('penanggung_jawab_user_id', 64)->nullable();
            // Satu dokumen, satu lokasi: yang diperiksa adalah apa yang ada di satu tempat.
            $table->ulid('lokasi_aset_id');
            $table->date('tanggal');
            $table->text('keterangan')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('diselesaikan_pada')->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'aset_tr_monitoring_scope_ix');
            $table->index(['tenant_id', 'status', 'tanggal'], 'aset_tr_monitoring_laporan_ix');
            $table->foreign(['tenant_id', 'lokasi_aset_id'], 'aset_tr_monitoring_lokasi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_lokasi_aset')->restrictOnDelete();
        });

        // Nomor terbit per entitas legal, jadi dua entitas legal pada satu tenant boleh memegang
        // nomor yang sama. Parsial karena tabelnya mengarsipkan (lihat standar module).
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_monitoring_kode_unique '.
            'ON aset_tr_monitoring_aset (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL'
        );

        // Satu aset per baris. Kolom `sistem_*`, nilai, dan `hasil` kosong selama draf dan
        // dibekukan saat dokumen diselesaikan, supaya laporan tahun depan tetap menyebut keadaan
        // register pada saat pemeriksaan, bukan keadaan hari laporan dicetak.
        Schema::create('aset_tr_monitoring_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('monitoring_aset_id');
            $table->unsignedInteger('line_number');
            $table->ulid('aset_id');
            // Temuan pemeriksa: ada atau tidak ada secara fisik. Kosong berarti belum diperiksa.
            $table->boolean('ada')->nullable();
            // Kondisi fisik yang dilihat, dari master kondisi aset. Temuan saja, tidak ditulis ke aset.
            $table->ulid('kondisi_aset_id')->nullable();
            $table->text('keterangan')->nullable();
            $table->string('sistem_lifecycle_state', 30)->nullable();
            $table->ulid('sistem_lokasi_id')->nullable();
            $table->ulid('sistem_org_unit_id')->nullable();
            $table->string('sistem_custodian_user_id', 64)->nullable();
            $table->decimal('nilai_perolehan', 18, 2)->nullable();
            $table->decimal('akumulasi_penyusutan', 18, 2)->nullable();
            $table->decimal('nilai_buku', 18, 2)->nullable();
            $table->string('hasil', 20)->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            // Nomor baris tidak dipakai ulang, juga sesudah barisnya diarsipkan: lampiran menempel
            // ke nomor baris.
            $table->unique(['tenant_id', 'monitoring_aset_id', 'line_number'], 'aset_tr_monitoring_line_uq');
            $table->index(['tenant_id', 'aset_id'], 'aset_tr_monitoring_line_aset_ix');
            $table->foreign(['tenant_id', 'monitoring_aset_id'], 'aset_tr_monitoring_line_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_monitoring_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_monitoring_line_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'kondisi_aset_id'], 'aset_tr_monitoring_line_kondisi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_kondisi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sistem_lokasi_id'], 'aset_tr_monitoring_line_lokasi_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_lokasi_aset')->restrictOnDelete();
        });

        // Satu aset paling banyak sekali pada satu dokumen: dua baris untuk aset yang sama berarti
        // dua temuan yang dapat saling bertentangan. Parsial karena baris yang dikeluarkan
        // pemeriksa diarsipkan, dan aset itu boleh ditambahkan lagi.
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_monitoring_line_aset_unique '.
            'ON aset_tr_monitoring_aset_details (tenant_id, monitoring_aset_id, aset_id) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('aset_tr_monitoring_aset');
        AuditColumns::attach('aset_tr_monitoring_aset_details');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_tr_monitoring_aset_details');
        Schema::dropIfExists('aset_tr_monitoring_aset');
    }
};
