<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Posting group aset: akun-akun yang dipakai jurnal aset satu group, per tanggal berlaku
 * (feed posting finance, TODO 8.1). Padanannya *FA Posting Groups* di Business Central dan
 * *fixed asset posting profile* di F&O.
 *
 * **Kenapa bertanggal berlaku, bukan satu baris per group.** Akun group bisa berganti, misalnya
 * saat konsultan memecah akun kendaraan. Jurnal yang tanggal postingnya sebelum pergantian tetap
 * harus memakai akun lama ketika dibentuk ulang, jadi baris lama tidak ditimpa. Posting memakai
 * baris dengan `effective_from` terbesar yang tidak melewati tanggal postingnya.
 *
 * **Kenapa akun tanpa foreign key.** Id akun adalah id daftar akun referensi milik Core
 * (`DaftarAkun`, K-05). Module tidak menyentuh tabel Core; keberadaan dan status akun diperiksa
 * lewat kontrak saat disimpan, dan diperiksa lagi oleh penerbit posting saat jurnal terbit.
 *
 * Setiap kolom akun boleh kosong. Akun yang belum dipetakan tidak menghalangi apa pun di modul
 * ini: posting yang membutuhkannya tertahan di Core dengan jalan pintas ke layar ini (K-18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_m_posting_group', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('group_aset_id');
            $table->date('effective_from');
            $table->ulid('acquisition_account_id')->nullable();
            $table->ulid('accumulated_depreciation_account_id')->nullable();
            $table->ulid('depreciation_expense_account_id')->nullable();
            $table->ulid('payable_account_id')->nullable();
            $table->ulid('clearing_account_id')->nullable();
            $table->ulid('input_vat_account_id')->nullable();
            $table->ulid('opening_balance_offset_account_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'group_aset_id', 'effective_from']);
            $table->foreign(['tenant_id', 'group_aset_id'])
                ->references(['tenant_id', 'id'])
                ->on('aset_m_group_aset')
                ->restrictOnDelete();
        });

        // Parsial: baris yang diarsipkan tidak boleh menghalangi tanggal yang sama dipakai lagi
        // (docs/dev/02-module-standard.md, "Indeks unik pada kode bisnis wajib parsial").
        DB::statement(
            'CREATE UNIQUE INDEX aset_m_posting_group_berlaku_unique '.
            'ON aset_m_posting_group (tenant_id, group_aset_id, effective_from) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS aset_m_posting_group_berlaku_unique');
        Schema::dropIfExists('aset_m_posting_group');
    }
};
