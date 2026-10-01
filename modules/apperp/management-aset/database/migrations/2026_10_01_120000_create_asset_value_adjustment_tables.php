<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen penyesuaian nilai aset: penurunan nilai (write-down, impairment) atau kenaikan nilai
 * (appreciation, revaluasi) atas satu buku penyusutan, untuk satu atau banyak aset.
 *
 * Padanan baris jurnal aset tetap ber-*FA Posting Type* `Write-Down` atau `Appreciation` di Business
 * Central, dan transaksi *Write-down adjustment* atau *Revaluation* di jurnal aset tetap Dynamics 365
 * F&O. Jenis dan buku ada di header, karena keduanya menentukan akun dan apakah jurnalnya dikirim ke
 * aplikasi finance; baris hanya membawa aset dan nilainya.
 *
 * Draf boleh diubah dan diarsipkan. Memposting mengubah nilai buku aset dan menerbitkan jurnalnya di satu
 * transaksi, lalu dokumennya terkunci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_tr_penyesuaian_nilai_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            $table->ulid('responsible_org_unit_id')->index();
            // `write_down` atau `appreciation`.
            $table->string('jenis', 20);
            $table->ulid('buku_id');
            // Tanggal posting jurnal dan tanggal nilai buku berubah.
            $table->date('tanggal');
            // Alasan penyesuaian; ikut ke keterangan jurnal di aplikasi finance.
            $table->text('keterangan');
            $table->string('status', 20)->default('draft');
            $table->timestamp('diposting_pada')->nullable();
            // Posting finance yang terbit saat dokumen diposting; kosong bila tidak ada baris yang
            // dijurnal (buku tidak di-post ke finance, atau dokumennya masih draf).
            $table->string('posting_id', 120)->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'aset_tr_penyesuaian_nilai_scope_ix');
            $table->foreign(['tenant_id', 'buku_id'], 'aset_tr_penyesuaian_nilai_buku_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_buku_penyusutan')->restrictOnDelete();
        });

        // Nomor terbit per entitas legal. Parsial karena draf yang diarsipkan tidak memakai ulang nomornya,
        // tetapi indeks unik pada kode bisnis tabel yang mengarsipkan selalu parsial (standar module).
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_penyesuaian_nilai_kode_unique '.
            'ON aset_tr_penyesuaian_nilai_aset (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_tr_penyesuaian_nilai_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('penyesuaian_nilai_aset_id');
            $table->unsignedInteger('line_number');
            $table->ulid('aset_id');
            // Selalu positif; arah penyesuaiannya dibawa `jenis` header.
            $table->decimal('nilai', 18, 2);
            $table->text('keterangan')->nullable();
            // Kosong selama draf, dibekukan saat diposting supaya dokumen tetap menyebut nilai buku pada
            // saat penyesuaian, bukan nilai buku hari dokumen dibuka.
            $table->decimal('nilai_buku_sebelum', 18, 2)->nullable();
            $table->decimal('nilai_buku_sesudah', 18, 2)->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            // Nomor baris tidak dipakai ulang, juga sesudah barisnya diarsipkan: lampiran menempel ke nomor
            // baris.
            $table->unique(['tenant_id', 'penyesuaian_nilai_aset_id', 'line_number'], 'aset_tr_penyesuaian_nilai_line_uq');
            $table->index(['tenant_id', 'aset_id'], 'aset_tr_penyesuaian_nilai_line_aset_ix');
            $table->foreign(['tenant_id', 'penyesuaian_nilai_aset_id'], 'aset_tr_penyesuaian_nilai_line_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_penyesuaian_nilai_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_penyesuaian_nilai_line_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
        });

        // Satu aset paling banyak sekali pada satu dokumen. Parsial: baris yang dikeluarkan diarsipkan, dan
        // aset itu boleh ditambahkan lagi.
        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_penyesuaian_nilai_line_aset_unique '.
            'ON aset_tr_penyesuaian_nilai_aset_details (tenant_id, penyesuaian_nilai_aset_id, aset_id) WHERE deleted_at IS NULL'
        );

        AuditColumns::attach('aset_tr_penyesuaian_nilai_aset');
        AuditColumns::attach('aset_tr_penyesuaian_nilai_aset_details');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_tr_penyesuaian_nilai_aset_details');
        Schema::dropIfExists('aset_tr_penyesuaian_nilai_aset');
    }
};
