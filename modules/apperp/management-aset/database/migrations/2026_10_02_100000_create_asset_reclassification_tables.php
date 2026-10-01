<?php

use App\Platform\Modules\Contracts\AuditColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen reklasifikasi aset: memindahkan aset ke group aset lain, atau memecah sebagian nilainya ke aset
 * baru. Padanan *FA Reclass. Journal* Business Central (`Reclassify Acq. Cost %`, `Reclassify Depreciation`,
 * `Reclassify Write-Down`, `Reclassify Appreciation`) dan pemecahan aset Dynamics 365 F&O.
 *
 * Tiga tabel:
 *
 * - **header** — jenis (`pindah_group` atau `pecah`), tanggal, alasan, status draf/diposting, dan posting
 *   finance yang terbit;
 * - **baris** — satu aset asal per baris, dengan group tujuan, dan untuk pecah persentase atau nilai
 *   perolehan yang dipindah serta nama aset barunya. Aset baru yang lahir dari baris itu dicatat di baris;
 * - **buku** — yang benar-benar dipindah per buku aset saat diposting: harga perolehan, akumulasi
 *   penyusutan, penurunan nilai, kenaikan nilai, dan nilai sisa. Ditulis sekali saat posting dan tidak
 *   pernah diubah, seperti FA Ledger Entry ber-*Reclassification Entry* di BC; laporan nilai buku dan
 *   rekonsiliasi membaca mutasi reklasifikasinya dari sini. Karena tidak pernah diarsipkan, ia tidak
 *   punya `deleted_at`, sama seperti periode penyusutan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_tr_reklasifikasi_aset', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->ulid('legal_entity_id')->index();
            $table->ulid('responsible_org_unit_id')->index();
            // `pindah_group` atau `pecah`.
            $table->string('jenis', 20);
            // Tanggal posting jurnal dan tanggal nilai berpindah.
            $table->date('tanggal');
            // Alasan reklasifikasi; ikut ke keterangan jurnal di aplikasi finance.
            $table->text('keterangan');
            $table->string('status', 20)->default('draft');
            $table->timestamp('diposting_pada')->nullable();
            // Posting finance yang terbit saat diposting; kosong bila tidak ada baris yang berpindah group
            // pada buku yang di-post ke finance.
            $table->string('posting_id', 120)->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'legal_entity_id', 'responsible_org_unit_id', 'status'], 'aset_tr_reklasifikasi_scope_ix');
        });

        DB::statement(
            'CREATE UNIQUE INDEX aset_tr_reklasifikasi_kode_unique '.
            'ON aset_tr_reklasifikasi_aset (tenant_id, legal_entity_id, kode) WHERE deleted_at IS NULL'
        );

        Schema::create('aset_tr_reklasifikasi_aset_details', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('reklasifikasi_aset_id');
            $table->unsignedInteger('line_number');
            $table->ulid('aset_id');
            // Wajib untuk pindah group; untuk pecah kosong berarti aset baru tetap di group aset asal.
            $table->ulid('group_aset_tujuan_id')->nullable();
            // Pecah saja: salah satu dari persentase atau nilai perolehan yang dipindah.
            $table->decimal('persen', 9, 6)->nullable();
            $table->decimal('nilai_perolehan', 18, 2)->nullable();
            // Pecah saja: nama aset baru; kosong berarti nama aset asal.
            $table->string('nama_aset_baru', 255)->nullable();
            $table->text('keterangan')->nullable();
            // Dibekukan saat diposting: group asal, aset baru yang lahir (pecah), dan nilai perolehan register
            // yang dipindah.
            $table->ulid('group_aset_asal_id')->nullable();
            $table->ulid('aset_baru_id')->nullable();
            $table->decimal('nilai_perolehan_dipindah', 18, 2)->nullable();
            AuditColumns::add($table);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            // Nomor baris tidak dipakai ulang: lampiran menempel ke nomor baris.
            $table->unique(['tenant_id', 'reklasifikasi_aset_id', 'line_number'], 'aset_tr_reklasifikasi_line_uq');
            $table->index(['tenant_id', 'aset_id'], 'aset_tr_reklasifikasi_line_aset_ix');
            $table->index(['tenant_id', 'aset_baru_id'], 'aset_tr_reklasifikasi_line_baru_ix');
            $table->foreign(['tenant_id', 'reklasifikasi_aset_id'], 'aset_tr_reklasifikasi_line_header_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_reklasifikasi_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_id'], 'aset_tr_reklasifikasi_line_aset_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'aset_baru_id'], 'aset_tr_reklasifikasi_line_baru_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'group_aset_tujuan_id'], 'aset_tr_reklasifikasi_line_tujuan_fk')
                ->references(['tenant_id', 'id'])->on('aset_m_group_aset')->restrictOnDelete();
        });

        Schema::create('aset_tr_reklasifikasi_aset_buku', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('reklasifikasi_aset_id');
            $table->ulid('reklasifikasi_aset_detail_id');
            // Disalin dari header supaya laporan tidak perlu menelusuri balik ke dokumennya.
            $table->date('tanggal');
            $table->string('jenis', 20);
            $table->ulid('buku_id');
            $table->ulid('aset_asal_id');
            $table->ulid('buku_aset_asal_id');
            $table->ulid('group_aset_asal_id');
            $table->ulid('aset_tujuan_id');
            $table->ulid('buku_aset_tujuan_id');
            $table->ulid('group_aset_tujuan_id');
            // Yang dipindah, selalu positif: keluar dari buku aset asal, masuk ke buku aset tujuan.
            $table->decimal('nilai_perolehan', 18, 2)->default(0);
            $table->decimal('akumulasi_penyusutan', 18, 2)->default(0);
            $table->decimal('penurunan_nilai', 18, 2)->default(0);
            $table->decimal('kenaikan_nilai', 18, 2)->default(0);
            $table->decimal('nilai_sisa', 18, 2)->default(0);
            // Baris ini dibawa jurnal `asset.reclassification`: group berubah dan buku ini buku yang di-post
            // ke finance.
            $table->boolean('dijurnal')->default(false);
            AuditColumns::add($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'reklasifikasi_aset_detail_id', 'buku_id'], 'aset_tr_reklasifikasi_buku_uq');
            $table->index(['tenant_id', 'buku_aset_asal_id'], 'aset_tr_reklasifikasi_buku_asal_ix');
            $table->index(['tenant_id', 'buku_aset_tujuan_id'], 'aset_tr_reklasifikasi_buku_tujuan_ix');
            $table->index(['tenant_id', 'aset_asal_id', 'tanggal'], 'aset_tr_reklasifikasi_buku_aset_ix');
            $table->foreign(['tenant_id', 'reklasifikasi_aset_detail_id'], 'aset_tr_reklasifikasi_buku_line_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_reklasifikasi_aset_details')->restrictOnDelete();
            $table->foreign(['tenant_id', 'buku_aset_asal_id'], 'aset_tr_reklasifikasi_buku_asal_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_buku_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'buku_aset_tujuan_id'], 'aset_tr_reklasifikasi_buku_tujuan_fk')
                ->references(['tenant_id', 'id'])->on('aset_tr_buku_aset')->restrictOnDelete();
        });

        AuditColumns::attach('aset_tr_reklasifikasi_aset');
        AuditColumns::attach('aset_tr_reklasifikasi_aset_details');
        AuditColumns::attach('aset_tr_reklasifikasi_aset_buku');
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_tr_reklasifikasi_aset_buku');
        Schema::dropIfExists('aset_tr_reklasifikasi_aset_details');
        Schema::dropIfExists('aset_tr_reklasifikasi_aset');
    }
};
